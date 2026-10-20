<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListPosts extends Tool
{
    use SerializesPosts;

    protected string $description = 'List recent blog posts (newest first) with status and SEO score. Filter by status or search text.';

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,scheduled,published,archived'],
            'search' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $posts = $this->postModel()::query()
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$s}%")
                ->orWhere('body', 'like', "%{$s}%")))
            ->latest()
            ->limit($data['limit'] ?? 20)
            ->get();

        return Response::text($this->json($posts->map(fn ($p) => $this->summary($p))->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['draft', 'scheduled', 'published', 'archived'])->description('Filter by status'),
            'search' => $schema->string()->description('Text to search in title and body'),
            'limit' => $schema->integer()->description('Max results, default 20'),
        ];
    }
}
