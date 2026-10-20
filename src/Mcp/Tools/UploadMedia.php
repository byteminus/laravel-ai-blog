<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use ByteMinus\AiBlog\Media\MediaException;
use ByteMinus\AiBlog\Media\MediaLibrary;
use ByteMinus\AiBlog\Models\Media;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Receives an image and stores it for the blog, returning a public address and the Markdown for it.
 *
 * ChatGPT hands over a file from the conversation (one it generated or one the person attached) through
 * the `file` parameter: _meta["openai/fileParams"] tells it to replace that argument with an object holding
 * a temporary download_url, which is fetched here and never repeated in the answer.
 */
class UploadMedia extends Tool
{
    use SerializesPosts;

    protected string $description = 'Store an image for the blog and get its public URL and Markdown. Give exactly one of: file (an image from this conversation, such as one you generated), source_url (a public https image address) or image_base64 (with mime_type). PNG, JPEG or WebP; it is converted to WebP and metadata is removed. Pass post_id and role to attach it at once, or use attach-media later.';

    protected ?array $meta = ['openai/fileParams' => ['file']];

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'file' => ['nullable'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string'],
            'mime_type' => ['nullable', 'string', 'max:64'],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'post_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'in:featured,inline'],
        ]);

        $given = array_keys(array_filter([
            'file' => filled($data['file'] ?? null),
            'source_url' => filled($data['source_url'] ?? null),
            'image_base64' => filled($data['image_base64'] ?? null),
        ]));
        if (count($given) !== 1) {
            return Response::error('Give exactly one image source: file, source_url or image_base64'.($given ? ' (got '.implode(', ', $given).').' : '.'));
        }

        $key = 'ai-blog-media:'.($request->user()?->getAuthIdentifier() ?? 'guest');
        if (RateLimiter::tooManyAttempts($key, (int) config('ai-blog.media.uploads_per_hour', 60))) {
            return Response::error('Too many image uploads. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.');
        }
        RateLimiter::hit($key, 3600);

        $post = null;
        if (filled($data['post_id'] ?? null)) {
            $post = $this->postModel()::query()->find($data['post_id']);
            if ($post === null) {
                return Response::error("There is no post {$data['post_id']}.");
            }
        }

        $library = app(MediaLibrary::class);
        $details = array_intersect_key($data, array_flip(['alt_text', 'title', 'caption']));

        try {
            $stored = match ($given[0]) {
                'file' => $library->fromUrl($this->downloadUrl($data['file']), $details, $request->user(), 'chatgpt_file'),
                'source_url' => $library->fromUrl($data['source_url'], $details, $request->user()),
                'image_base64' => $library->fromBase64($data['image_base64'], $details, $request->user()),
            };

            $media = $stored['media'];
            $attached = null;
            if ($post !== null) {
                if (! $library->mayUse($media, $request->user())) {
                    return Response::error('This image belongs to someone else.');
                }
                $attached = $library->attach($post, $media, $data['role'] ?? 'inline', $data['alt_text'] ?? null, $data['caption'] ?? null);
            }
        } catch (MediaException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured(array_filter(self::describe($media) + [
            'duplicate' => $stored['duplicate'],
            'attached' => $attached === null ? null : ['post' => $this->summary($post), 'placement' => $attached['placement'], 'changed' => $attached['changed']],
        ], fn ($value) => $value !== null));
    }

    /** @return array<string, mixed> */
    public static function describe(Media $media): array
    {
        return [
            'media_id' => $media->getKey(),
            'url' => $media->url(),
            'markdown' => $media->markdown(),
            'width' => $media->width,
            'height' => $media->height,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'alt_text' => $media->alt_text,
        ];
    }

    private function downloadUrl(mixed $file): string
    {
        $url = is_array($file) ? ($file['download_url'] ?? null) : null;
        if (! is_string($url) || $url === '') {
            throw new MediaException('The file was not handed over: this client sent '.(is_string($file) ? 'a file name' : 'no download address').' instead of the image. Pass the image as source_url or image_base64.');
        }

        return $url;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'file' => $schema->object([
                'download_url' => $schema->string()->required(),
                'file_id' => $schema->string()->required(),
                'mime_type' => $schema->string(),
                'file_name' => $schema->string(),
            ])->description('An image from this conversation (for example one you just generated). ChatGPT fills this in.'),
            'source_url' => $schema->string()->description('A public https:// address of a PNG, JPEG or WebP image'),
            'image_base64' => $schema->string()->description('The image bytes, base64 encoded (a data: prefix is fine)'),
            'mime_type' => $schema->string()->enum(['image/png', 'image/jpeg', 'image/webp'])->description('With image_base64'),
            'alt_text' => $schema->string()->description('What the image shows, for screen readers and search engines'),
            'title' => $schema->string()->description('A short name; also used in the file name'),
            'caption' => $schema->string(),
            'post_id' => $schema->integer()->description('Attach to this post right away'),
            'role' => $schema->string()->enum(['featured', 'inline'])->description('With post_id: the header image, or at the end of the body (default inline)'),
        ];
    }
}
