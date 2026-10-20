<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\AiBlog;
use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateDraft extends Tool
{
    use SerializesPosts;

    protected string $description = 'Create a new post as a DRAFT. Body is Markdown. Returns the post with its SEO score and fixes.';

    public function handle(Request $request): Response
    {
        $aiBlog = app(AiBlog::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'target_keyword' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ]);

        $post = $this->postModel()::create($data + ['status' => 'draft', 'ai_assistance' => 'drafted']);
        $report = $aiBlog->score($post);
        $post->forceFill(['seo_score' => $report->score])->save();

        return Response::text($this->json([
            'post' => $this->summary($post),
            'seo' => $report->toArray(),
            'note' => 'Saved as a draft. A human (or the scheduler, after schedule-post) publishes.',
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'body' => $schema->string()->description('Markdown body')->required(),
            'excerpt' => $schema->string(),
            'target_keyword' => $schema->string()->description('The search phrase the post targets'),
            'seo_description' => $schema->string()->description('120–160 characters'),
        ];
    }
}
