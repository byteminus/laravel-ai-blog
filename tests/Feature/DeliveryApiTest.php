<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Tests\Feature;

use ByteMinus\AiBlog\Models\Category;
use ByteMinus\AiBlog\Models\Post;
use ByteMinus\AiBlog\Tests\TestCase;

class DeliveryApiTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai-blog.delivery.enabled', true);
        $app['config']->set('ai-blog.seo.site_name', 'Acme Blog');
    }

    private function post(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Queues explained',
            'slug' => 'queues-explained',
            'body' => 'Queues move slow work out of the request.',
            'seo_description' => 'How Laravel queues work and when to use them.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_it_is_off_by_default(): void
    {
        $config = require __DIR__.'/../../config/ai-blog.php';

        $this->assertFalse($config['delivery']['enabled']);
    }

    public function test_drafts_scheduled_and_future_posts_never_leak(): void
    {
        $live = $this->post();
        $draft = $this->post(['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'published_at' => null]);
        $scheduled = $this->post(['title' => 'Soon', 'slug' => 'soon', 'status' => 'scheduled', 'published_at' => null, 'scheduled_for' => now()->addDay()]);
        $future = $this->post(['title' => 'Future', 'slug' => 'future', 'published_at' => now()->addDay()]);

        $this->getJson('/api/blog/v1/posts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'queues-explained');

        $this->getJson('/api/blog/v1/posts?status=draft')->assertJsonCount(1, 'data');

        foreach ([$draft, $scheduled, $future] as $hidden) {
            $this->getJson('/api/blog/v1/posts/'.$hidden->id)->assertNotFound();
            $this->getJson('/api/blog/v1/posts/by-slug/'.$hidden->slug)->assertNotFound();
        }

        $this->getJson('/api/blog/v1/posts/'.$live->id)->assertOk();
    }

    public function test_a_post_is_available_by_slug_with_seo_metadata_and_no_internal_fields(): void
    {
        $this->post([
            'featured_image' => '/storage/queues.png',
            'featured_image_alt' => 'A queue',
            'seo_score' => 91,
            'ai_assistance' => 'drafted',
            'meta' => ['secret' => 'x'],
        ]);

        $response = $this->getJson('/api/blog/v1/posts/by-slug/queues-explained')
            ->assertOk()
            ->assertJsonPath('data.title', 'Queues explained')
            ->assertJsonPath('data.body', 'Queues move slow work out of the request.')
            ->assertJsonPath('data.seo.description', 'How Laravel queues work and when to use them.')
            ->assertJsonPath('data.seo.canonical_url', url('/blog/queues-explained'))
            ->assertJsonPath('data.seo.open_graph.site_name', 'Acme Blog')
            ->assertJsonPath('data.featured_image.url', url('/storage/queues.png'))
            ->assertJsonPath('data.seo.json_ld.@type', 'BlogPosting');

        $json = $response->getContent();
        foreach (['seo_score', 'ai_assistance', 'scheduled_for', 'secret'] as $internal) {
            $this->assertStringNotContainsString($internal, $json);
        }
    }

    public function test_the_list_is_paginated_and_omits_bodies(): void
    {
        foreach (range(1, 5) as $n) {
            $this->post(['title' => "Post {$n}", 'slug' => "post-{$n}", 'published_at' => now()->subMinutes($n)]);
        }

        $this->getJson('/api/blog/v1/posts?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.slug', 'post-3')
            ->assertJsonMissingPath('data.0.body');

        $this->getJson('/api/blog/v1/posts?per_page=9999')->assertJsonPath('meta.per_page', 50);
    }

    public function test_responses_carry_cache_headers_and_honour_if_none_match(): void
    {
        $this->post();

        $first = $this->getJson('/api/blog/v1/posts/by-slug/queues-explained')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('max-age=300', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $first->headers->get('Cache-Control'));
        $this->assertNotNull($first->headers->get('Last-Modified'));

        $this->getJson('/api/blog/v1/posts/by-slug/queues-explained', ['If-None-Match' => $etag])
            ->assertStatus(304);

        $this->getJson('/api/blog/v1/posts/by-slug/queues-explained', ['If-None-Match' => '"stale"'])
            ->assertOk();
    }

    public function test_posts_can_be_filtered_by_category_and_categories_count_only_published_posts(): void
    {
        $news = Category::create(['name' => 'News']);
        Category::create(['name' => 'Empty']);

        $this->post(['category_id' => $news->id]);
        $this->post(['title' => 'Hidden', 'slug' => 'hidden', 'status' => 'draft', 'published_at' => null, 'category_id' => $news->id]);
        $this->post(['title' => 'Other', 'slug' => 'other']);

        $this->getJson('/api/blog/v1/posts?category=news')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.slug', 'news');

        $this->getJson('/api/blog/v1/posts?category=nope')->assertNotFound();

        $this->getJson('/api/blog/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'empty')
            ->assertJsonPath('data.0.posts_count', 0)
            ->assertJsonPath('data.1.slug', 'news')
            ->assertJsonPath('data.1.posts_count', 1);
    }
}
