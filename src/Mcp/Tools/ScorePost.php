<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\AiBlog;
use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ScorePost extends Tool
{
    use SerializesPosts;

    protected string $description = 'Score a post\'s on-page SEO (0–100) and list the specific fixes, so you can apply them with update-post.';

    public function handle(Request $request): Response
    {
        $aiBlog = app(AiBlog::class);

        $data = $request->validate(['id' => ['required', 'integer']]);
        $post = $this->findPost($data['id']);
        $report = $aiBlog->score($post);
        $post->forceFill(['seo_score' => $report->score])->saveQuietly();

        return Response::text($this->json(['post' => $this->summary($post), 'seo' => $report->toArray()]));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }
}
