<?php

namespace ByteMinus\AiBlog\Mcp\Tools;

use ByteMinus\AiBlog\Mcp\Tools\Concerns\SerializesPosts;
use ByteMinus\AiBlog\Media\MediaException;
use ByteMinus\AiBlog\Media\MediaLibrary;
use ByteMinus\AiBlog\Models\Media;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class AttachMedia extends Tool
{
    use SerializesPosts;

    protected string $description = 'Put an uploaded image (media_id from upload-media) on a post: as the featured image (article header and social preview) or inline in the body, after a heading or at the end. The rest of the post is left as it is, and an image already in the body is not added twice. To place an image anywhere else, put its markdown into the body with update-post.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'post_id' => ['required', 'integer'],
            'media_id' => ['required', 'integer'],
            'placement' => ['required', 'in:featured,inline'],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'after_heading' => ['nullable', 'string', 'max:255'],
        ]);

        $post = $this->postModel()::query()->find($data['post_id']);
        $media = Media::query()->find($data['media_id']);
        if ($post === null || $media === null) {
            return Response::error($post === null ? "There is no post {$data['post_id']}." : "There is no image {$data['media_id']}. Upload it with upload-media first.");
        }

        $library = app(MediaLibrary::class);
        if (! $library->mayUse($media, $request->user())) {
            return Response::error('This image belongs to someone else.');
        }

        try {
            $result = $library->attach($post, $media, $data['placement'], $data['alt_text'] ?? null, $data['caption'] ?? null, $data['after_heading'] ?? null);
        } catch (MediaException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'post' => $this->summary($post->refresh()),
            'placement' => $result['placement'],
            'changed' => $result['changed'],
            'note' => $result['changed'] ? 'Attached.' : 'Nothing to change: the post already has this image there.',
        ] + UploadMedia::describe($media));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
            'media_id' => $schema->integer()->required(),
            'placement' => $schema->string()->enum(['featured', 'inline'])->required(),
            'alt_text' => $schema->string()->description('Defaults to the alt text given at upload'),
            'caption' => $schema->string()->description('Inline only: a line under the image'),
            'after_heading' => $schema->string()->description('Inline only: the text of a heading in the post; the image goes right under it. Leave out to add at the end.'),
        ];
    }
}
