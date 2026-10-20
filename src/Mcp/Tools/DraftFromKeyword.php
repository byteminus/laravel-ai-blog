<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\AiBlog;
use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class DraftFromKeyword extends Tool
{
    use SerializesPosts;

    protected string $description = 'Research a keyword into a brief, write a full draft from it, and save it as a DRAFT post. Uses the site\'s configured AI provider.';

    public function handle(Request $request): Response
    {
        $aiBlog = app(AiBlog::class);

        $data = $request->validate(['keyword' => ['required', 'string', 'max:255']]);

        $post = $aiBlog->draftFromKeyword($data['keyword']);
        $report = $aiBlog->score($post);

        return Response::text($this->json([
            'post' => $this->full($post),
            'brief' => $post->meta['brief'] ?? null,
            'seo' => $report->toArray(),
            'note' => 'Saved as a draft for human review. Mark anything tagged [verify: ...] for fact-checking.',
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['keyword' => $schema->string()->description('The search phrase to target')->required()];
    }
}
