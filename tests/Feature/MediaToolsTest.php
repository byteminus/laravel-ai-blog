<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Tests\Feature;

use ByteMinus\AiBlog\Bus\Actor;
use ByteMinus\AiBlog\Fetching\HostResolver;
use ByteMinus\AiBlog\Mcp\BlogServer;
use ByteMinus\AiBlog\Mcp\Tools\AttachMedia;
use ByteMinus\AiBlog\Mcp\Tools\UploadMedia;
use ByteMinus\AiBlog\Models\Media;
use ByteMinus\AiBlog\Models\Post;
use ByteMinus\AiBlog\Tests\Fixtures\User;
use ByteMinus\AiBlog\Tests\TestCase;
use ByteMinus\AiBlog\Tests\Unit\Fetching\FakeHostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;

class MediaToolsTest extends TestCase
{
    private User $editor;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai-blog.routes.enabled', true);
        $app['config']->set('app.url', 'https://blog.test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Tool::class) || ! function_exists('imagewebp')) {
            $this->markTestSkipped('Needs laravel/mcp and GD with WebP.');
        }

        $this->app['view']->addNamespace('seo-test', __DIR__.'/../Fixtures/views');
        config()->set('ai-blog.routes.layout', 'seo-test::layout');
        config()->set('filesystems.disks.public.url', 'https://blog.test/storage');
        Storage::fake('public', ['url' => 'https://blog.test/storage']);

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $this->editor = User::create(['name' => 'Editor']);
        Gate::define(Actor::GATE, fn ($user) => $user->name === 'Editor');

        $this->resolve([]);
    }

    /** Every host resolves to a public address unless named here. */
    private function resolve(array $answers): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver($answers + [
            'files.oaiusercontent.com' => ['93.184.216.34'],
            'images.cdnhost.com' => ['93.184.216.34'],
            'big.cdnhost.com' => ['93.184.216.34'],
            'gone.cdnhost.com' => ['93.184.216.34'],
        ]));
    }

    private function png(int $width = 1600, int $height = 900): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A JPEG carrying a comment segment, standing in for EXIF/GPS metadata. */
    private function jpegWithMetadata(): string
    {
        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $comment = 'GPS-SECRET-LOCATION';

        return substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2);
    }

    private function upload(array $arguments, ?User $as = null): TestResponse
    {
        return BlogServer::actingAs($as ?? $this->editor)->tool(UploadMedia::class, $arguments);
    }

    private function structured(TestResponse $response): array
    {
        return (fn () => $this->structuredContent())->call($response) ?? [];
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Schedule Reels',
            'slug' => 'schedule-reels',
            'body' => "Intro paragraph.\n\n## Step one\n\nDo this.\n\n## Step two\n\nThen that.",
            'status' => 'draft',
        ]);
    }

    public function test_it_tells_chatgpt_which_parameter_takes_a_file(): void
    {
        $tool = (new UploadMedia)->toArray();

        $this->assertSame(['file'], $tool['_meta']['openai/fileParams']);
        $this->assertSame('object', $tool['inputSchema']['properties']['file']['type']);
        $this->assertEqualsCanonicalizing(['download_url', 'file_id'], $tool['inputSchema']['properties']['file']['required']);
        $this->assertSame('upload-media', $tool['name']);
    }

    public function test_it_stores_a_base64_image_as_webp_with_a_public_url(): void
    {
        $response = $this->upload(['image_base64' => 'data:image/png;base64,'.base64_encode($this->png()), 'mime_type' => 'image/png', 'alt_text' => 'Reels workflow', 'title' => 'Reels workflow']);

        $response->assertOk()->assertHasNoErrors();
        $data = $this->structured($response);
        $media = Media::sole();

        $this->assertSame($media->id, $data['media_id']);
        $this->assertSame(1600, $data['width']);
        $this->assertSame('image/webp', $data['mime_type']);
        $this->assertMatchesRegularExpression('#^https://blog\.test/storage/blog/reels-workflow-[0-9a-f]{12}\.webp$#', $data['url']);
        $this->assertSame('![Reels workflow]('.$data['url'].')', $data['markdown']);
        $this->assertFalse($data['duplicate']);
        $this->assertSame('base64', $media->source);
        $this->assertSame((string) $this->editor->id, (string) $media->uploader_id);

        $stored = Storage::disk('public')->get($media->path);
        $this->assertSame('WEBP', substr($stored, 8, 4));
        $this->assertSame(strlen($stored), $data['size_bytes']);
    }

    public function test_it_scales_down_wide_images_and_strips_metadata(): void
    {
        config()->set('ai-blog.media.max_width', 800);
        $data = $this->structured($this->upload(['image_base64' => base64_encode($this->png(1600, 900))]));
        $this->assertSame([800, 450], [$data['width'], $data['height']]);

        $data = $this->structured($this->upload(['image_base64' => base64_encode($this->jpegWithMetadata())]));
        $this->assertStringNotContainsString('GPS-SECRET-LOCATION', Storage::disk('public')->get(Media::find($data['media_id'])->path));
    }

    public function test_the_same_image_is_stored_once(): void
    {
        $png = base64_encode($this->png());
        $first = $this->structured($this->upload(['image_base64' => $png]));
        $second = $this->structured($this->upload(['image_base64' => $png, 'alt_text' => 'Filled in later']));

        $this->assertSame($first['media_id'], $second['media_id']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame('Filled in later', $second['alt_text']);
        $this->assertCount(1, Storage::disk('public')->allFiles('blog'));
    }

    public function test_it_wants_exactly_one_source(): void
    {
        $this->upload([])->assertHasErrors(['Give exactly one image source']);
        $this->upload(['image_base64' => base64_encode($this->png()), 'source_url' => 'https://images.cdnhost.com/a.png'])
            ->assertHasErrors(['got source_url, image_base64']);
        $this->assertSame(0, Media::count());
    }

    public function test_it_refuses_files_that_are_not_supported_images(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $this->upload(['image_base64' => base64_encode($gif)])->assertHasErrors(['not a PNG, JPEG or WebP']);
        $this->upload(['image_base64' => base64_encode('<?php echo "hi"; ?>'), 'mime_type' => 'image/png'])->assertHasErrors(['not a PNG, JPEG or WebP']);
        $this->upload(['image_base64' => '***not base64***'])->assertHasErrors(['not valid base64']);
        $this->assertSame(0, Media::count());
    }

    public function test_it_refuses_oversized_images(): void
    {
        config()->set('ai-blog.media.max_bytes', 1000);
        $this->upload(['image_base64' => base64_encode($this->png())])->assertHasErrors(['larger than']);

        config()->set('ai-blog.media.max_bytes', 15 * 1024 * 1024);
        config()->set('ai-blog.media.max_pixels', 1000);
        $this->upload(['image_base64' => base64_encode($this->png(1600, 900))])->assertHasErrors(['too large in pixels']);
        $this->assertSame(0, Media::count());
    }

    public function test_it_downloads_a_chatgpt_file_and_never_repeats_its_address(): void
    {
        Http::fake(['files.oaiusercontent.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $download = 'https://files.oaiusercontent.com/file-abc?sig=SECRET-SIGNATURE';

        $response = $this->upload(['file' => ['download_url' => $download, 'file_id' => 'file-abc', 'mime_type' => 'image/png', 'file_name' => 'generated.png'], 'alt_text' => 'A generated cover']);

        $response->assertOk()->assertHasNoErrors()->assertDontSee('SECRET-SIGNATURE')->assertDontSee('oaiusercontent');
        $this->assertSame('chatgpt_file', Media::sole()->source);
        Http::assertSent(fn (Request $request) => $request->url() === $download && $request->method() === 'GET');
    }

    public function test_it_explains_when_chatgpt_sends_a_file_name_instead_of_the_file(): void
    {
        Http::fake();
        $this->upload(['file' => '/mnt/data/image.png'])->assertHasErrors(['The file was not handed over']);
        $this->upload(['file' => ['file_id' => 'file-abc']])->assertHasErrors(['The file was not handed over']);
        Http::assertNothingSent();
    }

    public function test_it_downloads_a_public_source_url(): void
    {
        Http::fake(['images.cdnhost.com/*' => Http::response($this->png(), 200)]);

        $this->upload(['source_url' => 'https://images.cdnhost.com/cover.png'])->assertHasNoErrors();
        $this->assertSame('url', Media::sole()->source);
    }

    public function test_it_will_not_fetch_from_private_networks_or_unsafe_addresses(): void
    {
        Http::fake(['*' => Http::response($this->png(), 200)]);
        $this->resolve(['internal.acme.com' => ['10.0.0.5'], 'metadata.acme.com' => ['169.254.169.254'], 'mixed.acme.com' => ['93.184.216.34', '127.0.0.1'], 'v6.acme.com' => ['::1']]);

        foreach (['https://internal.acme.com/a.png', 'https://metadata.acme.com/latest', 'https://mixed.acme.com/a.png', 'https://v6.acme.com/a.png', 'https://127.0.0.1/a.png', 'https://localhost/a.png', 'https://db.internal/a.png'] as $url) {
            $this->upload(['source_url' => $url])->assertHasErrors(['private network']);
        }
        $this->upload(['source_url' => 'http://images.cdnhost.com/a.png'])->assertHasErrors(['Only https://']);
        $this->upload(['source_url' => 'file:///etc/passwd'])->assertHasErrors(['Only https://']);
        $this->upload(['source_url' => 'https://user:pw@images.cdnhost.com/a.png'])->assertHasErrors(['not allowed']);
        $this->upload(['source_url' => 'https://images.cdnhost.com:8443/a.png'])->assertHasErrors(['standard https port']);

        Http::assertNothingSent();
    }

    public function test_it_checks_every_redirect_again(): void
    {
        $this->resolve(['internal.acme.com' => ['192.168.1.10']]);
        Http::fake([
            'images.cdnhost.com/*' => Http::response('', 302, ['Location' => 'https://internal.acme.com/secret.png']),
            'internal.acme.com/*' => Http::response($this->png(), 200),
        ]);

        $this->upload(['source_url' => 'https://images.cdnhost.com/a.png'])->assertHasErrors(['private network']);
        Http::assertSentCount(1);
    }

    public function test_it_refuses_a_download_that_is_too_large_or_fails(): void
    {
        config()->set('ai-blog.media.max_bytes', 2000);
        Http::fake([
            'big.cdnhost.com/*' => Http::response(str_repeat('x', 5000), 200),
            'gone.cdnhost.com/*' => Http::response('', 404),
        ]);

        $this->upload(['source_url' => 'https://big.cdnhost.com/a.png'])->assertHasErrors(['larger than']);
        $this->upload(['source_url' => 'https://gone.cdnhost.com/a.png'])->assertHasErrors(['HTTP 404']);
    }

    public function test_a_storage_failure_is_reported_without_details(): void
    {
        config()->set('ai-blog.media.disk', 'no-such-disk');

        $this->upload(['image_base64' => base64_encode($this->png())])
            ->assertHasErrors(['could not be saved to storage'])->assertDontSee('no-such-disk');
        $this->assertSame(0, Media::count());
    }

    public function test_the_returned_url_points_at_the_stored_file(): void
    {
        $data = $this->structured($this->upload(['image_base64' => base64_encode($this->png())]));
        $path = ltrim(substr($data['url'], strlen('https://blog.test/storage/')), '/');

        Storage::disk('public')->assertExists($path);
        $this->assertSame(Media::sole()->path, $path);
    }

    public function test_it_attaches_a_featured_image_shown_in_the_header_and_social_card(): void
    {
        $media = Media::find($this->structured($this->upload(['image_base64' => base64_encode($this->png()), 'alt_text' => 'Reels calendar']))['media_id']);
        $post = $this->makePost(['status' => 'published', 'published_at' => now()->subDay()]);

        $response = BlogServer::actingAs($this->editor)->tool(AttachMedia::class, ['post_id' => $post->id, 'media_id' => $media->id, 'placement' => 'featured']);

        $response->assertHasNoErrors();
        $post->refresh();
        $this->assertSame('/storage/'.$media->path, $post->featured_image);
        $this->assertSame('Reels calendar', $post->featured_image_alt);
        $this->assertSame($post->body, "Intro paragraph.\n\n## Step one\n\nDo this.\n\n## Step two\n\nThen that.");

        $this->get('/blog/schedule-reels')->assertOk()
            ->assertSee('<img class="ai-blog-featured" src="'.url('/storage/'.$media->path).'" alt="Reels calendar"', false)
            ->assertSee('<meta property="og:image" content="'.url('/storage/'.$media->path).'">', false);
    }

    public function test_it_inserts_an_inline_image_under_a_heading_once(): void
    {
        $media = Media::find($this->structured($this->upload(['image_base64' => base64_encode($this->png())]))['media_id']);
        $post = $this->makePost();
        $attach = fn (array $extra = []) => BlogServer::actingAs($this->editor)->tool(AttachMedia::class, $extra + ['post_id' => $post->id, 'media_id' => $media->id, 'placement' => 'inline', 'alt_text' => 'Step one [screen]', 'caption' => 'The first screen', 'after_heading' => 'Step one']);

        $attach()->assertHasNoErrors();
        $this->assertSame(
            "Intro paragraph.\n\n## Step one\n\n![Step one screen](".$media->url().")\n*The first screen*\n\n\nDo this.\n\n## Step two\n\nThen that.",
            $post->refresh()->body,
        );

        $this->assertFalse($this->structured($attach())['changed']);
        $this->assertSame(1, substr_count($post->refresh()->body, $media->url()));

        $attach(['after_heading' => 'Nope'])->assertHasNoErrors();
        BlogServer::actingAs($this->editor)->tool(AttachMedia::class, ['post_id' => $this->makePost(['slug' => 'other'])->id, 'media_id' => $media->id, 'placement' => 'inline', 'after_heading' => 'Missing heading'])
            ->assertHasErrors(['No heading "Missing heading"']);
    }

    public function test_upload_can_attach_at_once_and_the_page_renders_it_lazily(): void
    {
        $post = $this->makePost(['status' => 'published', 'published_at' => now()->subDay()]);

        $data = $this->structured($this->upload(['image_base64' => base64_encode($this->png()), 'alt_text' => 'Inline chart', 'post_id' => $post->id, 'role' => 'inline']));

        $this->assertSame('inline', $data['attached']['placement']);
        $this->assertStringEndsWith('![Inline chart]('.$data['url'].")\n", $post->refresh()->body);
        $this->get('/blog/schedule-reels')->assertOk()->assertSee('<img loading="lazy" decoding="async" src="'.$data['url'].'" alt="Inline chart"', false);
    }

    public function test_someone_else_s_image_cannot_be_attached_without_blog_rights(): void
    {
        $media = Media::find($this->structured($this->upload(['image_base64' => base64_encode($this->png())]))['media_id']);
        $stranger = User::create(['name' => 'Stranger']);
        $post = $this->makePost();

        BlogServer::actingAs($stranger)->tool(AttachMedia::class, ['post_id' => $post->id, 'media_id' => $media->id, 'placement' => 'featured'])
            ->assertHasErrors(['belongs to someone else']);
        $this->upload(['image_base64' => base64_encode($this->png()), 'post_id' => $post->id, 'role' => 'featured'], $stranger)
            ->assertHasErrors(['belongs to someone else']);
        $this->assertNull($post->refresh()->featured_image);

        BlogServer::actingAs($this->editor)->tool(AttachMedia::class, ['post_id' => 999, 'media_id' => $media->id, 'placement' => 'featured'])->assertHasErrors(['no post 999']);
        BlogServer::actingAs($this->editor)->tool(AttachMedia::class, ['post_id' => $post->id, 'media_id' => 999, 'placement' => 'featured'])->assertHasErrors(['no image 999']);
    }

    public function test_uploads_are_rate_limited_per_user(): void
    {
        config()->set('ai-blog.media.uploads_per_hour', 2);
        $png = base64_encode($this->png());

        $this->upload(['image_base64' => $png])->assertHasNoErrors();
        $this->upload(['image_base64' => $png])->assertHasNoErrors();
        $this->upload(['image_base64' => $png])->assertHasErrors(['Too many image uploads']);
    }
}
