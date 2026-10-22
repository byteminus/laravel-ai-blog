# Laravel AI Blog — the AI-native blog package for Laravel

[![tests](https://github.com/byteminus/laravel-ai-blog/actions/workflows/tests.yml/badge.svg)](https://github.com/byteminus/laravel-ai-blog/actions)
[![Latest Version](https://img.shields.io/packagist/v/byteminus/laravel-ai-blog.svg)](https://packagist.org/packages/byteminus/laravel-ai-blog)
[![Downloads](https://img.shields.io/packagist/dt/byteminus/laravel-ai-blog.svg)](https://packagist.org/packages/byteminus/laravel-ai-blog)
[![PHP](https://img.shields.io/packagist/php-v/byteminus/laravel-ai-blog.svg)](https://packagist.org/packages/byteminus/laravel-ai-blog)
[![License](https://img.shields.io/github/license/byteminus/laravel-ai-blog.svg)](LICENSE.md)

**Website and docs:** [byteminus.github.io/laravel-ai-blog](https://byteminus.github.io/laravel-ai-blog/) · For AI assistants: [llms.txt](https://byteminus.github.io/laravel-ai-blog/llms.txt)

**Install it, and your blog thinks.** Laravel AI Blog is a free, open-source blog package for Laravel 10 to 13. It
plans topics, drafts *with* you, scores every post for on-page SEO, links itself, keeps itself fresh — and lets
Claude, ChatGPT, Claude Code or any MCP agent write to it.

- **Headless by default.** Models, migrations, a trait for your *existing* Post model. No admin panel forced on you. Flip one flag for `/blog` routes, Blade components, RSS and JSON-LD.
- **SEO that nobody else ships.** An on-page score with plain-English fixes, internal-link suggestions with anchor text, stale-post detection, BlogPosting + FAQPage schema.
- **AI as your editor, not your ghostwriter.** Built on the official [laravel/ai](https://github.com/laravel/ai) SDK — any provider. Brief → draft, meta descriptions, rewrites, refresh suggestions, translations. Everything lands as a **draft**. Nothing auto-publishes unless you say so.
- **Agent-ready.** A built-in [laravel/mcp](https://github.com/laravel/mcp) server: *"write a post on X, score it, link it to related posts, schedule for Monday."*
- **Free. MIT.** No Nova license, no Filament lock-in, no SaaS tether.

## Requirements

- PHP 8.2 to 8.5 with the `curl`, `dom`, `libxml` and `mbstring` extensions
- Laravel 10, 11, 12 or 13

Both ends of those ranges run in CI on every push: the newest dependencies and the oldest ones the constraints
allow. Laravel 10 and 11 are end of life; they are supported for apps that have not upgraded, not recommended
for new ones. On PHP 8.4 and later the text extractor uses PHP's spec-compliant HTML5 parser; on older PHP it
falls back to libxml2's HTML parser, which gives the same results on the pages the tests cover but stops at
about 256 levels of nesting (it returns no text for a page nested deeper, never partial text).

## Install

```bash
composer require byteminus/laravel-ai-blog
php artisan vendor:publish --tag=ai-blog-migrations
php artisan migrate
```

Optional, for AI and agents:

```bash
composer require laravel/ai laravel/mcp
```

## 60-second tour

```php
use ByteMinus\AiBlog\Facades\AiBlog;
use ByteMinus\AiBlog\Models\Post;

// Score any post. Deterministic, no API key needed.
$report = $post->seoReport();
$report->score;    // 72
$report->grade();  // "B"
$report->fixes();  // ["Include \"laravel queues\" in the title.", "Add at least two links — ..."]

// Internal links, with anchor text, from your own published posts.
$post->suggestedLinks();   // [['post' => Post, 'anchor' => 'queue workers', 'score' => 0.41], ...]

// AI: keyword → brief → full draft. Saved as a DRAFT for a human to review.
$draft = AiBlog::draftFromKeyword('laravel queues');

// AI: fill in a missing keyword + meta description on an existing post.
AiBlog::enrichSeo($post);

// Publishing is explicit.
$post->schedule(now()->addDays(2));   // then: php artisan ai-blog:publish-scheduled
$post->publish();
```

```bash
php artisan ai-blog:audit            # score every post, list fixes and links
php artisan ai-blog:audit --fix      # let the AI fill missing SEO fields first
php artisan ai-blog:audit --stale    # posts due for a refresh
```

## Already have a Post model?

Keep it. Add the trait, point the config at your model, done.

```php
use ByteMinus\AiBlog\Concerns\HasBlogSeo;

class Post extends Model
{
    use HasBlogSeo;

    // Override only what differs from the defaults (title, body, slug, seo_title, seo_description, target_keyword)
    public function getBodyField(): string { return 'content'; }
}
```

```php
// config/ai-blog.php
'models' => ['post' => App\Models\Post::class],
```

## Let agents write your blog

```php
// config/ai-blog.php
'mcp' => [
    'enabled' => true,
    'path' => '/mcp/ai-blog',
    'middleware' => ['auth:sanctum'],
],
```

Then connect:

```bash
claude mcp add --transport http my-blog https://example.com/mcp/ai-blog --header "Authorization: Bearer <token>"
```

Tools: `list-posts`, `get-post`, `create-draft`, `update-post`, `draft-from-keyword`, `score-post`,
`suggest-links`, `schedule-post`, `upload-media`, `attach-media`. There is deliberately **no `publish-post` tool** — agents draft,
score, link and schedule; a human (or the scheduler, after a human scheduled it) publishes.

The MCP tools need laravel/mcp ^1.0 (Laravel 12.41 or later).

### Images (`upload-media`, `attach-media`)

`upload-media` stores an image and answers with `media_id`, `url`, `markdown`, `width`, `height`, `mime_type`,
`size_bytes` and `alt_text`. Give it exactly one source:

- `file`: a file from the conversation. The tool declares `_meta["openai/fileParams"] = ["file"]`, so ChatGPT
  replaces the argument with `{download_url, file_id, mime_type, file_name}` for a file it generated or was given.
  The temporary `download_url` is downloaded and never repeated in the answer.
- `source_url`: a public `https://` address.
- `image_base64` (with `mime_type`).

Downloads go through the same URL, DNS and IP checks as `SafeFetcher` (no private, loopback, link-local or cloud
metadata addresses, the connection pinned to the checked address, every redirect checked again), https on port 443
only. The image must really be a PNG, JPEG or WebP within `ai-blog.media` limits; it is re-encoded as WebP with GD
(metadata such as EXIF and GPS is dropped), scaled down to `max_width`, stored once per content hash on the
`ai-blog.media.disk` (default `public`, so run `php artisan storage:link`), and served from that disk's public URL.

`attach-media` puts an image on a post: `placement: featured` sets `featured_image` and its alt text (article header,
`og:image`, `twitter:image`, JSON-LD), and `placement: inline` adds the Markdown image under `after_heading`, or at
the end, leaving the rest of the body as it was and never adding the same image twice. `upload-media` takes `post_id`
and `role` to do both in one call. Images in a post's body render with `loading="lazy"` and `decoding="async"`.
A user may attach an image they uploaded, or any image when the `manageAiBlog` gate allows them.

Needs `ext-gd` with WebP support, and the media migration:

```bash
php artisan vendor:publish --tag=ai-blog-migrations
php artisan migrate
```

For Claude.ai and ChatGPT custom connectors, use laravel/mcp's OAuth support (`Mcp::oauthRoutes()` with Passport).

## Safe fetching (research)

`SafeFetcher` reads a public web page, feed or JSON document so the AI has real sources to work from. Run it
from a queued job or an Artisan command: it **refuses to run inside a web request**.

```php
use ByteMinus\AiBlog\Fetching\Feeds\FeedParser;
use ByteMinus\AiBlog\Fetching\SafeFetcher;
use ByteMinus\AiBlog\Fetching\TextExtractor;

$document = app(SafeFetcher::class)->fetch('https://example.com/feed.xml');

$feed = app(FeedParser::class)->parseDocument($document);   // RSS, Atom, RSS 1.0 or JSON Feed
$page = app(TextExtractor::class)->extract($document);      // readable text, title, author, date
$page->untrusted();                                         // wrap it before it goes near a model
```

On the first URL and on every redirect (at most 5) it checks that the URL is plain http(s) with no credentials,
a standard port and no internal host names; resolves the host itself and requires every address to be public;
**pins the connection to the address it checked**, so DNS rebinding can't move the request inside your network;
obeys `robots.txt`; ignores proxies and cookies; and caps the body size and total time. Anything it returns is
untrusted data: hand it to a model only through `TextExtractor` and `UntrustedText`.

## Command bus

Every change can go through one bus, so a dashboard, an Artisan command, a job and an AI agent all get the same
rules: the actor must hold the command's **ability**, every request carries an **idempotency key** (a retry
replays the stored result instead of running twice), and each outcome is written to an **audit trail**.

```php
use ByteMinus\AiBlog\Bus\Actor;
use ByteMinus\AiBlog\Bus\CommandBus;
use ByteMinus\AiBlog\Bus\CommandContext;
use ByteMinus\AiBlog\Bus\CommandHandler;
use ByteMinus\AiBlog\Bus\CommandOutcome;
use ByteMinus\AiBlog\Bus\CommandRequest;

final class RenamePost extends CommandHandler
{
    public function ability(): ?string { return 'posts.write'; }

    public function rules(): array { return ['title' => ['required', 'string', 'max:120']]; }

    public function handle(CommandContext $context): CommandOutcome
    {
        // ...call your service here...
        return CommandOutcome::completed(['title' => $context->params['title']], 'post', '42');
    }
}
```

```php
// config/ai-blog.php
'commands' => ['handlers' => ['post.rename' => RenamePost::class]],

// AppServiceProvider: nobody manages the blog until you say who may
Gate::define('manageAiBlog', fn ($user) => $user->is_admin);

// anywhere
$receipt = app(CommandBus::class)->dispatch(
    Actor::user($request->user()),
    new CommandRequest('post.rename', idempotencyKey: 'rename-42-v1', params: ['title' => 'New title']),
);

$receipt->result();    // what the caller may see
$receipt->replayed;    // true when this was a retry
```

Pass `dryRun: true` to preview a command without changing anything. Publish and run the migrations to create the
`commands` and `audit_events` tables.

## Public routes (opt-in)

```php
'routes' => ['enabled' => true, 'prefix' => 'blog', 'layout' => 'layouts.app'],
```

Gives you `/blog`, `/blog/{slug}`, `/blog/category/{slug}`, `/blog/tag/{slug}`, `/blog/feed`, and the
Blade components `<x-ai-blog::meta-tags>`, `<x-ai-blog::structured-data>`, `<x-ai-blog::post-body>`.

Your layout needs `@yield('title')`, `@yield('content')` and `@stack('head')`; the pages put their tags in the stack.

### What the pages send to search engines

- **Post pages:** a canonical URL (the post's own address, or its `canonical_url` when you set one), `og:` and
  `twitter:` cards with an absolute image URL, `article:published_time` and `article:modified_time`, a
  `robots` tag that allows large image previews, an RSS link, and `BlogPosting` JSON-LD with the page address and
  publisher. A `## Question?` heading followed by an answer adds `FAQPage` JSON-LD.
- **Index, category and tag pages:** a description, a canonical URL, `prev`/`next` links, an RSS link and a
  distinct title on every page of results ("Blog — Page 2 — My Site").
- **Feed:** RSS 2.0 with language, last build date and permalink GUIDs.
- Tags with nothing to say are left out rather than sent empty: a post with no description gets no
  `description` tag. Set `ai-blog.seo.description` (or `AI_BLOG_SEO_DESCRIPTION`) for the blog's own description.

## Delivery API (opt-in, read-only)

For a separate front end (Next.js, Nuxt, a mobile app), serve published content as versioned JSON. It is **off by
default**; set `AI_BLOG_DELIVERY_ENABLED=true` or `'delivery' => ['enabled' => true]` in `config/ai-blog.php`.

| Route (default prefix `api/blog/v1`) | Returns |
| --- | --- |
| `GET /posts?page=&per_page=&category=&tag=` | Paginated list (`per_page` max 50), without bodies |
| `GET /posts/{id}` and `GET /posts/by-slug/{slug}` | One post with Markdown `body` and `seo` incl. JSON-LD |
| `GET /categories` | Categories with their published post counts |

Every query goes through the model's `published()` scope and nothing in the request can widen it, so drafts,
scheduled and future-dated posts return 404 or are left out. Only an allow-list of fields is sent (no `meta`,
`seo_score` or `ai_assistance`). Responses carry `ETag`, `Last-Modified` and `Cache-Control: public, max-age=300`
and answer `If-None-Match` with `304`. Change the prefix, middleware (default `api`, `throttle:60,1`) and
`cache_ttl` under `ai-blog.delivery`.

## Scheduler

```php
// routes/console.php
Schedule::command('ai-blog:publish-scheduled')->everyMinute();
```

## FAQ

**Does it work without an AI provider?** Yes. Scoring, link suggestions, structured data, the feed and the routes
need no API key. The AI features are optional and use [laravel/ai](https://github.com/laravel/ai) when you install it.

**Will it publish posts by itself?** No. Everything the AI or an agent writes lands as a draft. A post goes live when a
person publishes it, or when the scheduler reaches a time set with `schedule()` or the `schedule-post` tool.

**Can I keep my own Post model?** Yes. Add the `HasBlogSeo` trait and point `ai-blog.models.post` at your model.

**Which Laravel and PHP versions does it support?** Laravel 10, 11, 12 and 13 on PHP 8.2 to 8.5.

**Is it free?** Yes, MIT licensed, with no paid tier.

## Philosophy

AI that writes thirty posts a month from a keyword list is what search engines are built to bury.
Laravel AI Blog makes a **human writer faster** and an **agent safer**: every AI action produces a draft,
marks unverified claims as `[verify: ...]`, and records how much AI was involved (`ai_assistance`).
Nothing goes live without a person publishing it or scheduling it.

## Roadmap

- [x] Headless core, SEO score, internal links, stale detection, JSON-LD, RSS
- [x] AI brief → draft, enrich, rewrite, refresh, translate (via laravel/ai)
- [x] MCP server for agents
- [x] Safe fetching: SSRF-guarded fetcher, feed parser, text extraction
- [x] Command bus with idempotency and an audit trail
- [ ] Built-in XML sitemap
- [ ] Semantic linking with embeddings
- [ ] Topic intelligence: content gaps and clusters from your existing posts
- [ ] Filament adapter · Nova adapter · Livewire editor
- [ ] Multilingual posts with hreflang
- [ ] Scheduled pipelines ("two drafts a week from this keyword list")

See [CONTRIBUTING.md](CONTRIBUTING.md) — there's a `good first issue` for most of these.

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability privately.

## License

MIT.
