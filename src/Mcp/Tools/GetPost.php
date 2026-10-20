<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetPost extends Tool
{
    use SerializesPosts;

    protected string $description = 'Read one post in full (body is Markdown). Always read before updating.';

    public function handle(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        return Response::text($this->json($this->full($this->findPost($data['id']))));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('Post ID')->required()];
    }
}
