<?php

namespace ByteMinus\AiBlog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An image stored for the blog: uploaded by an agent (upload-media) and attached to posts (attach-media).
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $hash sha256 of the bytes that were uploaded, so the same image is stored once
 * @property string $mime_type
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property string|null $alt_text
 * @property string|null $title
 * @property string|null $caption
 * @property string $source chatgpt_file|url|base64
 * @property string|null $uploader_type
 * @property int|string|null $uploader_id
 */
class Media extends Model
{
    protected $guarded = [];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function getTable(): string
    {
        return config('ai-blog.table_prefix', 'ai_blog_').'media';
    }

    public function uploader(): MorphTo
    {
        return $this->morphTo();
    }

    /** The full public address, for a browser, a feed or an agent. */
    public function url(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);

        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }

    /**
     * The address to save on a post's featured image: root relative when the file is served by this app,
     * so it survives a change of host (the meta tags make it absolute again).
     */
    public function address(): string
    {
        $url = $this->url();
        $app = rtrim((string) config('app.url'), '/');

        return $app !== '' && str_starts_with($url, $app.'/') ? substr($url, strlen($app)) : $url;
    }

    public function markdown(?string $alt = null): string
    {
        return '!['.self::cleanAlt($alt ?? $this->alt_text ?? '').']('.$this->url().')';
    }

    /** Alt text that cannot break out of Markdown image syntax. */
    public static function cleanAlt(?string $alt): string
    {
        $alt = preg_replace('/[\[\]\r\n\t]+/u', ' ', strip_tags((string) $alt)) ?? '';

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $alt) ?? ''), 250, '');
    }
}
