<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** Names a raster image's type from the signature its body starts with, for a server that does not declare it. */
final class ImageMagicBytes
{
    private const array SIGNATURES = [
        "\xFF\xD8\xFF" => 'image/jpeg',
        "\x89PNG" => 'image/png',
        'GIF8' => 'image/gif',
    ];

    private const array FTYP_BRANDS = [
        'avif' => 'image/avif',
        'avis' => 'image/avif',
        'heic' => 'image/heic',
        'heix' => 'image/heic',
    ];

    public static function typeOf(string $bytes): ?string
    {
        foreach (self::SIGNATURES as $signature => $type) {
            if (str_starts_with($bytes, $signature)) {
                return $type;
            }
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return substr($bytes, 4, 4) === 'ftyp' ? self::FTYP_BRANDS[substr($bytes, 8, 4)] ?? null : null;
    }

    private function __construct()
    {
    }
}
