<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\AiBlog;
use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SuggestLinks extends Tool
{
    use SerializesPosts;

    protected string $description = 'Suggest internal links from this post to related posts, with anchor text. Insert them with update-post.';

    public function handle(Request $request): Response
    {
        $aiBlog = app(AiBlog::class);

        $data = $request->validate([
            'id' => ['required', 'integer'],
            'max' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $post = $this->findPost($data['id']);
        $links = $aiBlog->suggestLinks($post, $data['max'] ?? null);

        return Response::text($this->json($links->map(fn ($l) => [
            'target' => $this->summary($l['post']),
            'anchor' => $l['anchor'],
            'relevance' => $l['score'],
            'markdown' => "[{$l['anchor']}](/".config('ai-blog.routes.prefix', 'blog')."/{$l['post']->slug})",
        ])->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'max' => $schema->integer()->description('Max suggestions, default 5'),
        ];
    }
}
