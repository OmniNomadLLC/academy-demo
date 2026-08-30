<?php

namespace App\Support;

use GdImage;
use RuntimeException;

class StudentPhotoProcessor
{
    public const TARGET_SIZE = 720;

    public static function normalize(string $path, ?int $size = null): void
    {
        [$canvas, $type] = self::makeCanvas($path, $size);
        self::writeToPath($canvas, $type, $path);
        imagedestroy($canvas);
    }

    public static function previewDataUri(string $path, ?int $size = null): string
    {
        [$canvas] = self::makeCanvas($path, $size);

        ob_start();
        imagejpeg($canvas, null, 90);
        $binary = ob_get_clean();
        imagedestroy($canvas);

        if ($binary === false) {
            throw new RuntimeException('Unable to build preview image.');
        }

        return 'data:image/jpeg;base64,' . base64_encode($binary);
    }

    /**
     * @return array{GdImage,int}
     */
    protected static function makeCanvas(string $path, ?int $size = null): array
    {
        $info = @getimagesize($path);

        if (! $info) {
            throw new RuntimeException('Invalid image.');
        }

        [$width, $height, $type] = $info;
        $source = self::createSource($path, $type);
        $side = min($width, $height);
        $srcX = (int) max(0, ($width - $side) / 2);
        $srcY = (int) max(0, ($height - $side) / 2);
        $targetSize = $size ?? self::TARGET_SIZE;
        $canvas = imagecreatetruecolor($targetSize, $targetSize);

        if (self::supportsAlpha($type)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        } else {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
        }

        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $targetSize, $targetSize, $side, $side);
        imagedestroy($source);

        return [$canvas, $type];
    }

    protected static function createSource(string $path, int $type): GdImage
    {
        $resource = match ($type) {
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : imagecreatefromjpeg($path),
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            default => imagecreatefromstring((string) file_get_contents($path)),
        };

        if (! $resource instanceof GdImage) {
            throw new RuntimeException('Unsupported image type.');
        }

        return $resource;
    }

    protected static function supportsAlpha(int $type): bool
    {
        return in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }

    protected static function writeToPath(GdImage $canvas, int $type, string $path): void
    {
        $result = match ($type) {
            IMAGETYPE_PNG => imagepng($canvas, $path, 6),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($canvas, $path, 90) : imagejpeg($canvas, $path, 90),
            default => imagejpeg($canvas, $path, 90),
        };

        if ($result === false) {
            throw new RuntimeException('Unable to save processed image.');
        }
    }
}
