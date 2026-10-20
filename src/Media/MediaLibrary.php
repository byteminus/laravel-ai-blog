<?php

namespace ByteMinus\AiBlog\Media;

use ByteMinus\AiBlog\AiBlog;
use ByteMinus\AiBlog\Bus\Actor;
use ByteMinus\AiBlog\Models\Media;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Images for the blog: receive them (from bytes, an address or a file an agent hands over), store each one
 * once, and put them on posts as the featured image or in the body.
 */
class MediaLibrary
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly ImageDownloader $downloader,
    ) {}

    public function maxBytes(): int
    {
        return (int) config('ai-blog.media.max_bytes', 15 * 1024 * 1024);
    }

    /** Download from an address that must stay public, then store. */
    public function fromUrl(string $url, array $details = [], ?Authenticatable $uploader = null, string $source = 'url'): array
    {
        return $this->store($this->downloader->download($url, $this->maxBytes()), $details, $uploader, $source);
    }

    public function fromBase64(string $data, array $details = [], ?Authenticatable $uploader = null): array
    {
        $data = preg_replace('#^data:image/[a-z+.-]+;base64,#i', '', trim($data)) ?? '';
        if (strlen($data) > (int) ceil($this->maxBytes() / 3) * 4 + 4) {
            throw new MediaException('That image is larger than '.round($this->maxBytes() / 1048576, 1).' MB.');
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $data) ?? '', true);
        if ($bytes === false || $bytes === '') {
            throw new MediaException('image_base64 is not valid base64.');
        }

        return $this->store($bytes, $details, $uploader, 'base64');
    }

    /**
     * Validate, convert and store. The same image (by the hash of what was uploaded) is stored once: a second
     * upload returns the first record, filling in alt text, title or caption it did not have.
     *
     * @param  array{alt_text?: string|null, title?: string|null, caption?: string|null}  $details
     * @return array{media: Media, duplicate: bool}
     */
    public function store(string $bytes, array $details = [], ?Authenticatable $uploader = null, string $source = 'base64'): array
    {
        if (strlen($bytes) > $this->maxBytes()) {
            throw new MediaException('That image is larger than '.round($this->maxBytes() / 1048576, 1).' MB.');
        }

        $details = array_filter([
            'alt_text' => Media::cleanAlt($details['alt_text'] ?? null),
            'title' => Str::limit(trim(strip_tags((string) ($details['title'] ?? ''))), 255, ''),
            'caption' => Str::limit(trim(strip_tags((string) ($details['caption'] ?? ''))), 1000, ''),
        ], fn ($value) => $value !== '');

        $hash = hash('sha256', $bytes);
        $existing = Media::query()->where('hash', $hash)->first();
        if ($existing) {
            $missing = array_filter($details, fn ($value, $key) => blank($existing->{$key}), ARRAY_FILTER_USE_BOTH);
            if ($missing !== []) {
                $existing->forceFill($missing)->save();
            }

            return ['media' => $existing, 'duplicate' => true];
        }

        $image = $this->processor->process($bytes);

        $disk = (string) config('ai-blog.media.disk', 'public');
        $base = Str::limit(Str::slug($details['title'] ?? $details['alt_text'] ?? ''), 60, '') ?: 'image';
        $path = trim((string) config('ai-blog.media.directory', 'blog'), '/').'/'.$base.'-'.substr($hash, 0, 12).'.webp';

        try {
            $saved = Storage::disk($disk)->put($path, $image['bytes'], 'public');
        } catch (Throwable $e) {
            Log::error('ai-blog: storing an uploaded image failed', ['disk' => $disk, 'path' => $path, 'exception' => $e->getMessage()]);
            $saved = false;
        }
        if (! $saved) {
            throw new MediaException('The image could not be saved to storage. Try again in a moment.');
        }

        $media = Media::query()->create($details + [
            'disk' => $disk,
            'path' => $path,
            'hash' => $hash,
            'mime_type' => $image['mime_type'],
            'width' => $image['width'],
            'height' => $image['height'],
            'size_bytes' => strlen($image['bytes']),
            'source' => $source,
            'uploader_type' => $uploader instanceof Model ? $uploader->getMorphClass() : null,
            'uploader_id' => $uploader?->getAuthIdentifier(),
        ]);

        return ['media' => $media, 'duplicate' => false];
    }

    /**
     * Someone may use an image they uploaded, or any image when they may manage the blog.
     */
    public function mayUse(Media $media, ?Authenticatable $user): bool
    {
        if ($user === null) {
            return $media->uploader_id === null;
        }
        $own = $user instanceof Model && $media->uploader_type === $user->getMorphClass()
            && (string) $media->uploader_id === (string) $user->getAuthIdentifier();

        return $own || Gate::forUser($user)->allows(Actor::GATE);
    }

    /**
     * Put an image on a post (the configured post model, so its columns are read by name). Featured: the post's header image and social card. Inline: a Markdown image
     * in the body, after the named heading or at the end. The same image is never added to a body twice.
     *
     * @return array{changed: bool, placement: string, markdown: string|null}
     */
    public function attach(Model $post, Media $media, string $placement, ?string $alt = null, ?string $caption = null, ?string $afterHeading = null): array
    {
        $alt = Media::cleanAlt($alt) ?: ($media->alt_text ?: Media::cleanAlt($post->getAttribute('title')));

        if ($placement === 'featured') {
            $changed = $post->getAttribute('featured_image') !== $media->address() || $post->getAttribute('featured_image_alt') !== $alt;
            $post->forceFill(['featured_image' => $media->address(), 'featured_image_alt' => $alt]);
            $this->save($post);

            return ['changed' => $changed, 'placement' => 'featured', 'markdown' => null];
        }

        $body = (string) $post->getAttribute('body');
        $markdown = $media->markdown($alt);
        if (str_contains($body, $media->url()) || str_contains($body, '('.$media->address().')')) {
            return ['changed' => false, 'placement' => 'inline', 'markdown' => $markdown];
        }

        $caption = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($caption ?? $media->caption ?? ''))) ?? '');
        $block = $markdown.($caption !== '' ? "\n*".str_replace('*', '\*', $caption).'*' : '');

        $post->setAttribute('body', $afterHeading !== null && trim($afterHeading) !== ''
            ? $this->insertAfterHeading($body, $afterHeading, $block)
            : rtrim($body)."\n\n".$block."\n");
        $this->save($post);

        return ['changed' => true, 'placement' => 'inline', 'markdown' => $markdown];
    }

    private function insertAfterHeading(string $body, string $heading, string $block): string
    {
        $wanted = Str::lower(trim(ltrim(trim($heading), '#')));
        $lines = preg_split('/\R/', $body) ?: [];
        $fence = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fence = ! $fence;
            }
            if (! $fence && preg_match('/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/', $line, $m) && Str::lower(trim($m[1])) === $wanted) {
                array_splice($lines, $i + 1, 0, ['', $block, '']);

                return implode("\n", $lines);
            }
        }

        throw new MediaException("No heading \"{$heading}\" in that post. Read it with get-post and pass a heading exactly as written, or leave after_heading out to add the image at the end.");
    }

    private function save(Model $post): void
    {
        if ($post->isDirty() && $post->getAttribute('ai_assistance') === null) {
            $post->setAttribute('ai_assistance', 'assisted');
        }
        $post->save();

        $report = app(AiBlog::class)->score($post);
        $post->forceFill(['seo_score' => $report->score])->save();
    }
}
