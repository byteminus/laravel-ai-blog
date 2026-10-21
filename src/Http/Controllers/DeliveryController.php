<?php

namespace ByteMinus\AiBlog\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Read-only, versioned JSON delivery of published content for headless front ends.
 *
 * Every query starts from the model's `published()` scope and there is no
 * parameter that widens it, so drafts, scheduled and future-dated posts can
 * never be returned. Responses use an explicit allow-list of fields: internal
 * columns (`meta`, `ai_assistance`, `seo_score`, `scheduled_for`, ...) are not exposed.
 */
class DeliveryController extends Controller
{
    private const MAX_PER_PAGE = 50;

    protected function posts(): Builder
    {
        return config('ai-blog.models.post')::query()
            ->published()
            ->with(['category', 'tags'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function postsIndex(Request $request): JsonResponse
    {
        $query = $this->posts();

        if ($slug = $request->query('category')) {
            $category = config('ai-blog.models.category')::where('slug', (string) $slug)->firstOrFail();
            $query->where('category_id', $category->getKey());
        }

        if ($slug = $request->query('tag')) {
            $tag = config('ai-blog.models.tag')::where('slug', (string) $slug)->firstOrFail();
            $query->whereHas('tags', fn ($q) => $q->whereKey($tag->getKey()));
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) $request->query('per_page', 12)));
        $page = $query->paginate($perPage)->appends($request->query());

        return $this->respond($request, [
            'data' => $page->getCollection()->map(fn ($post) => $this->present($post, false))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'links' => [
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
        ], $page->getCollection()->max('updated_at'));
    }

    public function postsShow(Request $request, int $id): JsonResponse
    {
        return $this->single($request, $this->posts()->whereKey($id)->firstOrFail());
    }

    public function postsBySlug(Request $request, string $slug): JsonResponse
    {
        return $this->single($request, $this->posts()->where('slug', $slug)->firstOrFail());
    }

    public function categories(Request $request): JsonResponse
    {
        $model = config('ai-blog.models.category');
        $rows = $model::query()
            ->withCount(['posts' => fn ($q) => $q->published()])
            ->orderBy('name')
            ->get();

        return $this->respond($request, [
            'data' => $rows->map(fn ($c) => [
                'id' => $c->getKey(),
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'posts_count' => (int) $c->posts_count,
            ])->all(),
        ], $rows->max('updated_at'));
    }

    protected function single(Request $request, $post): JsonResponse
    {
        return $this->respond($request, ['data' => $this->present($post, true)], $post->updated_at);
    }

    /** @return array<string, mixed> */
    protected function present($post, bool $full): array
    {
        $url = $post->canonical_url ?: url(config('ai-blog.routes.prefix', 'blog').'/'.$post->slug);
        $image = $post->featured_image
            ? (Str::startsWith($post->featured_image, ['http://', 'https://']) ? $post->featured_image : url($post->featured_image))
            : null;

        $data = [
            'id' => $post->getKey(),
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'featured_image' => $image ? ['url' => $image, 'alt' => $post->featured_image_alt] : null,
            'category' => $post->category ? [
                'id' => $post->category->getKey(),
                'name' => $post->category->name,
                'slug' => $post->category->slug,
            ] : null,
            'tags' => $post->tags->map(fn ($t) => ['name' => $t->name, 'slug' => $t->slug])->values()->all(),
            'seo' => [
                'title' => $post->seoTitle(),
                'description' => $post->seoDescription() ?: null,
                'canonical_url' => $url,
                'robots' => 'index, follow, max-image-preview:large',
                'open_graph' => [
                    'type' => 'article',
                    'site_name' => config('ai-blog.seo.site_name'),
                    'image' => $image,
                ],
            ],
        ];

        if ($full) {
            $data['body'] = $post->bodyText(); // Markdown source
            $data['seo']['json_ld'] = $post->structuredData();
        }

        return $data;
    }

    /** @param  array<string, mixed>  $payload */
    protected function respond(Request $request, array $payload, $lastModified = null): JsonResponse
    {
        $response = response()->json($payload);
        $response->setEtag(sha1((string) $response->getContent()));

        if ($lastModified) {
            $response->setLastModified($lastModified);
        }

        $ttl = (int) config('ai-blog.delivery.cache_ttl', 300);
        $ttl > 0 ? $response->setPublic()->setMaxAge($ttl) : $response->headers->set('Cache-Control', 'no-store');
        $response->setVary('Accept');

        $response->isNotModified($request);

        return $response;
    }
}
