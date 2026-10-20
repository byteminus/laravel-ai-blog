<?php

namespace ByteMinus\AiBlog\Media;

/**
 * Checks that bytes really are a PNG, JPEG or WebP image within the limits, then re-encodes them as WebP
 * with GD. Re-encoding drops EXIF and every other piece of metadata; a JPEG is turned upright first,
 * since its orientation lives in the EXIF that is dropped. Wide images are scaled down to max_width.
 */
class ImageProcessor
{
    private const TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];

    /** @return array{bytes: string, width: int, height: int, mime_type: string, original_mime_type: string} */
    public function process(string $bytes): array
    {
        if ($bytes === '') {
            throw new MediaException('The image is empty.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            throw new MediaException('That file is not a PNG, JPEG or WebP image.');
        }

        [$width, $height] = $info;
        $side = (int) config('ai-blog.media.max_pixels', 8000);
        $megapixels = (float) config('ai-blog.media.max_megapixels', 40);
        if ($width < 1 || $height < 1 || $width > $side || $height > $side || $width * $height > $megapixels * 1_000_000) {
            throw new MediaException("That image is too large in pixels. Keep each side under {$side} px.");
        }

        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            throw new MediaException('This site cannot process images yet: PHP needs the GD extension with WebP support.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new MediaException('That image is damaged and could not be read.');
        }

        try {
            if ($info[2] === IMAGETYPE_JPEG) {
                $image = $this->upright($image, $bytes);
            }

            $maxWidth = (int) config('ai-blog.media.max_width', 2400);
            if ($maxWidth > 0 && imagesx($image) > $maxWidth) {
                $scaled = imagescale($image, $maxWidth, (int) round(imagesy($image) * $maxWidth / imagesx($image)));
                if ($scaled !== false) {
                    imagedestroy($image);
                    $image = $scaled;
                }
            }

            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $written = imagewebp($image, null, (int) config('ai-blog.media.webp_quality', 82));
            $out = (string) ob_get_clean();
            if (! $written || $out === '') {
                throw new MediaException('The image could not be converted.');
            }

            return [
                'bytes' => $out,
                'width' => imagesx($image),
                'height' => imagesy($image),
                'mime_type' => 'image/webp',
                'original_mime_type' => self::TYPES[$info[2]],
            ];
        } finally {
            imagedestroy($image);
        }
    }

    /** Applies a JPEG's EXIF orientation, when the exif extension can read it. */
    private function upright(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $turned = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };
        if ($turned instanceof \GdImage) {
            imagedestroy($image);

            return $turned;
        }

        return $image;
    }
}
