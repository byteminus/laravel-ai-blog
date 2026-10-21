<?php

use ByteMinus\AiBlog\Ai\LaravelAiAssistant;
use ByteMinus\AiBlog\Models\Category;
use ByteMinus\AiBlog\Models\Post;
use ByteMinus\AiBlog\Models\Tag;

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Swap in your own models. Any class here must extend (or behave like)
    | the package model it replaces. If you already have a Post model, add
    | the ByteMinus\AiBlog\Concerns\HasBlogSeo trait to it and point `post` at it.
    */
    'models' => [
        'post' => Post::class,
        'category' => Category::class,
        'tag' => Tag::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table prefix
    |--------------------------------------------------------------------------
    */
    'table_prefix' => 'ai_blog_',

    /*
    |--------------------------------------------------------------------------
    | Public routes (opt-in)
    |--------------------------------------------------------------------------
    | Headless by default. Flip `enabled` to get /blog, /blog/{slug},
    | /blog/category/{slug}, /blog/tag/{slug} and /blog/feed.
    */
    'routes' => [
        'enabled' => false,
        'prefix' => 'blog',
        'middleware' => ['web'],
        'layout' => 'layouts.app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery API (opt-in)
    |--------------------------------------------------------------------------
    | Read-only, versioned JSON for headless front ends: published posts
    | (list, by id, by slug), categories and SEO metadata, with pagination and
    | ETag / Cache-Control headers. Only published posts are ever returned.
    | `cache_ttl` is the public max-age in seconds (0 sends no-store).
    */
    'delivery' => [
        'enabled' => env('AI_BLOG_DELIVERY_ENABLED', false),
        'prefix' => 'api/blog/v1',
        'middleware' => ['api', 'throttle:60,1'],
        'cache_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'site_name' => env('APP_NAME', 'Blog'),
        'description' => env('AI_BLOG_SEO_DESCRIPTION'),
        'title_max' => 60,
        'description_min' => 120,
        'description_max' => 160,
        'min_words' => 600,
        'stale_after_days' => 365,
        'internal_links' => [
            'max_suggestions' => 5,
            'min_score' => 0.15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    | Built on the official laravel/ai SDK. `assistant` can be any class
    | implementing ByteMinus\AiBlog\Ai\Contracts\Assistant. Auto-publishing is OFF by
    | default and must be enabled knowingly — Laravel AI Blog assists writers, it
    | does not replace them.
    */
    'ai' => [
        'enabled' => env('AI_BLOG_AI_ENABLED', true),
        'assistant' => LaravelAiAssistant::class,
        'provider' => env('AI_BLOG_AI_PROVIDER'),
        'model' => env('AI_BLOG_AI_MODEL'),
        'auto_publish' => false,
        'style_guide' => 'Write clearly and concretely. Short paragraphs. No filler.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    | Every change goes through one command bus with an idempotency key and
    | an audit event. Add your own handlers here as 'name.action' => class.
    | Who may run commands as a person is decided by the `manageAiBlog`
    | gate, which denies everyone until you define it.
    */
    'commands' => [
        'handlers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    | Requires laravel/mcp. When `enabled`, Laravel AI Blog registers an MCP server
    | at `path` so Claude, ChatGPT, Claude Code or any agent can draft, edit,
    | score and schedule posts. Protect it with your auth middleware.
    */
    'mcp' => [
        'enabled' => env('AI_BLOG_MCP_ENABLED', false),
        'path' => '/mcp/ai-blog',
        'middleware' => ['auth:sanctum'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media (upload-media and attach-media)
    |--------------------------------------------------------------------------
    | Images an agent stores for the blog. Each is checked to be a real PNG,
    | JPEG or WebP, re-encoded as WebP with GD (which drops EXIF and other
    | metadata), scaled down to `max_width`, stored once per content hash on
    | `disk` under `directory`, and served from the disk's public URL. The
    | "public" disk needs `php artisan storage:link`. Requires ext-gd with WebP.
    */
    'media' => [
        'disk' => env('AI_BLOG_MEDIA_DISK', 'public'),
        'directory' => 'blog',
        'max_bytes' => 15 * 1024 * 1024,   // what may be uploaded or downloaded
        'max_pixels' => 8000,              // per side
        'max_megapixels' => 40,
        'max_width' => 2400,               // wider images are scaled down
        'webp_quality' => 82,
        'uploads_per_hour' => 60,          // per user
        'fetch_timeout' => 20,             // seconds, for source_url and ChatGPT files
        'fetch_max_redirects' => 3,
    ],

];
