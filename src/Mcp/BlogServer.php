<?php

namespace ByteMinus\AiBlog\Mcp;

use ByteMinus\AiBlog\Mcp\Tools\AttachMedia;
use ByteMinus\AiBlog\Mcp\Tools\CreateDraft;
use ByteMinus\AiBlog\Mcp\Tools\DraftFromKeyword;
use ByteMinus\AiBlog\Mcp\Tools\GetPost;
use ByteMinus\AiBlog\Mcp\Tools\ListPosts;
use ByteMinus\AiBlog\Mcp\Tools\SchedulePost;
use ByteMinus\AiBlog\Mcp\Tools\ScorePost;
use ByteMinus\AiBlog\Mcp\Tools\SuggestLinks;
use ByteMinus\AiBlog\Mcp\Tools\UpdatePost;
use ByteMinus\AiBlog\Mcp\Tools\UploadMedia;
use Laravel\Mcp\Server;

/**
 * Lets Claude, ChatGPT, Claude Code, Cursor — any MCP client — work the blog.
 *
 * Requires laravel/mcp. Registered by the service provider when ai-blog.mcp.enabled is true.
 * Publishing is intentionally NOT a tool: agents draft, score, link and schedule; a human publishes
 * (or the scheduler does, after a human scheduled it).
 */
class BlogServer extends Server
{
    protected string $name = 'Laravel AI Blog';

    protected string $version = '0.2.0';

    protected string $instructions = <<<'TXT'
    You are working inside a Laravel site's blog via Laravel AI Blog. You can list, read, create and update posts,
    draft a post from a keyword, score a post's on-page SEO, suggest internal links, and schedule posts.
    You can also store images (upload-media) and put them on posts (attach-media): the featured image, or
    inline in the body. Write alt text that says what the image shows.
    Always read a post before updating it. Never claim a post is live: you cannot publish — you can only
    schedule, and a human or the scheduler publishes. Preserve the site's house style from the style guide.
    TXT;

    protected array $tools = [
        ListPosts::class,
        GetPost::class,
        CreateDraft::class,
        UpdatePost::class,
        DraftFromKeyword::class,
        ScorePost::class,
        SuggestLinks::class,
        SchedulePost::class,
        UploadMedia::class,
        AttachMedia::class,
    ];

    protected array $resources = [
        Resources\StyleGuide::class,
    ];
}
