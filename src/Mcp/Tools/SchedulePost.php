<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SchedulePost extends Tool
{
    use SerializesPosts;

    protected string $description = 'Schedule a post to be published at a future time (ISO 8601). The scheduler publishes it; you cannot publish directly.';

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'at' => ['required', 'date', 'after:now'],
        ]);

        $post = $this->findPost($data['id'])->schedule(Carbon::parse($data['at']));

        return Response::text($this->json([
            'post' => $this->summary($post),
            'note' => 'Scheduled. It goes live when ai-blog:publish-scheduled runs after that time.',
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'at' => $schema->string()->description('ISO 8601 datetime in the future')->required(),
        ];
    }
}
