<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\AiBlog;
use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdatePost extends Tool
{
    use SerializesPosts;

    protected string $description = 'Update fields on an existing post. Only the fields you pass change. Status cannot be set here — use schedule-post.';

    public function handle(Request $request): Response
    {
        $aiBlog = app(AiBlog::class);

        $data = $request->validate([
            'id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'target_keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
        ]);

        $post = $this->findPost($data['id']);
        unset($data['id']);

        $post->fill($data);
        if ($post->isDirty() && $post->ai_assistance === null) {
            $post->ai_assistance = 'assisted';
        }
        $post->save();

        $report = $aiBlog->score($post);
        $post->forceFill(['seo_score' => $report->score])->save();

        return Response::text($this->json(['post' => $this->summary($post), 'seo' => $report->toArray()]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'title' => $schema->string(),
            'body' => $schema->string()->description('Full Markdown body (replaces existing)'),
            'excerpt' => $schema->string(),
            'target_keyword' => $schema->string(),
            'seo_title' => $schema->string(),
            'seo_description' => $schema->string(),
        ];
    }
}
