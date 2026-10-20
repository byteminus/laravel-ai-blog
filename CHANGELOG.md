# Changelog

## 0.2.0 - 2026-10-20

- MCP: new `upload-media` tool. Stores a PNG, JPEG or WebP image from a ChatGPT file (`_meta["openai/fileParams"]`), a public https URL or base64, re-encodes it as WebP without metadata, stores it once per content hash and answers with its public URL and Markdown. Optional `post_id` and `role` attach it at once.
- MCP: new `attach-media` tool. Sets a post's featured image, or adds an image inline under a heading or at the end of the body, without duplicates.
- New `media` table (`create_ai_blog_media_table` migration, publish it with `ai-blog-migrations`), `ByteMinus\AiBlog\Models\Media`, `ByteMinus\AiBlog\Media\MediaLibrary` and `ai-blog.media` config. Needs `ext-gd` with WebP.
- Pages: the default post view shows the featured image in the header; images in a post's body load lazily.
- Fix: every MCP tool now types `schema()` with `Illuminate\Contracts\JsonSchema\JsonSchema`, as laravel/mcp 1.x declares it. Before, loading any tool on laravel/mcp 1.x was a fatal error. The MCP server now needs laravel/mcp ^1.0 (Laravel 12.41+).
- Fix: tool descriptions name other tools the way clients see them (`update-post`, `schedule-post`).

- Breaking: the package moved to the byteminus organisation. Install `byteminus/laravel-ai-blog` instead of `hawkforge/laravel-ai-blog`, and change `use AiBlog\...` to `use ByteMinus\AiBlog\...` in your code. Published config files need the same change.
- Fix: the AI features now work with laravel/ai 1.x. The assistant looked for a `Laravel\Ai\Facades\Ai` class that laravel/ai does not have, so every AI call failed with "need the laravel/ai SDK" even when it was installed. It now sends each request through `Laravel\Ai\AnonymousAgent`.
- Docs: the migrations publish tag is `ai-blog-migrations` (the README said `laravel-ai-blog-migrations`, which publishes nothing), and MCP tool names are written the way clients see them, in kebab-case (`list-posts`, `schedule-post`).
- Website with docs, a 107-question FAQ, diagrams and an AI-readable layer (`llms.txt`, Markdown copies of every page): https://byteminus.github.io/laravel-ai-blog/

## 0.1.1 - 2026-10-14

- SEO: post pages now send a canonical URL by default (the post's own address), `og:url`, `og:site_name`, `article:modified_time`, an absolute `og:image` and `twitter:image`, and a `robots` tag that allows large image previews; empty description tags are no longer sent
- SEO: `BlogPosting` JSON-LD now includes the page `url`, the `publisher` and an absolute `image`
- SEO: index, category and tag pages send a description, canonical URL, `prev`/`next` links, an RSS link and a distinct title per page of results; new `ai-blog.seo.description` option
- Feed: RSS now declares its language and last build date and marks permalink GUIDs
- Docs: README says Laravel 10 to 13 throughout, lists what the pages send to search engines and answers common questions

## 0.1.0 - 2026-10-14

- Headless core: Post, Category, Tag; draft/scheduled/published lifecycle; `HasBlogSeo` trait for existing models
- SEO: on-page score with fixes, TF-IDF internal-link suggestions, stale-post detection, BlogPosting + FAQPage JSON-LD
- AI (laravel/ai): brief → draft, enrich SEO fields, rewrite, refresh suggestions, translate; `FakeAssistant` for tests and no-AI mode
- MCP (laravel/mcp): BlogServer with list/get/create/update/draft-from-keyword/score/suggest-links/schedule tools and a style-guide resource
- Commands: `ai-blog:publish-scheduled`, `ai-blog:audit`
- Opt-in public routes, RSS feed, Blade components
- Safe fetching: `SafeFetcher` with URL and IP policy, DNS pinning, robots.txt, size and time caps; RSS/Atom/RDF/JSON Feed parser; text extraction that marks fetched content as untrusted
- Command bus: abilities, idempotency keys, dry runs and an audit trail (`commands` and `audit_events` tables)
- Requires PHP 8.2 to 8.5 and Laravel 10 to 13; tested at the newest and the oldest dependency versions
- Text extraction uses PHP 8.4's HTML5 parser where available and libxml2's HTML parser on older PHP
