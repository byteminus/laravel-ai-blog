<?php

namespace ByteMinus\AiBlog\Support;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        static $converter = null;
        $converter ??= new CommonMarkConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]);

        return (string) $converter->convert($markdown);
    }

    /** Markdown → HTML → sanitized. Use this for anything an AI or an agent wrote. */
    public static function toSafeHtml(string $markdown): string
    {
        static $sanitizer = null;
        $sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->forceHttpsUrls(false)
                ->withMaxInputLength(2_000_000)
        );

        return self::lazyImages($sanitizer->sanitize(self::toHtml($markdown)));
    }

    /**
     * Images in a post's body load lazily and decode off the main thread; none of them is the page's
     * largest element (the featured image is, and it is not in the body). Runs on sanitized output, where
     * every tag is well formed.
     */
    private static function lazyImages(string $html): string
    {
        return preg_replace_callback('/<img\b(?![^>]*\bloading=)([^>]*)>/i', fn ($m) => '<img loading="lazy" decoding="async"'.$m[1].'>', $html) ?? $html;
    }
}
