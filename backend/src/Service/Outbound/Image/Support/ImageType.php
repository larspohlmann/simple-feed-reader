<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** A downloaded image's type: the one its response declares, or the one its bytes show when it declares none. */
final class ImageType
{
    private const array UNDECLARED_TYPES = ['', 'application/octet-stream'];

    public static function isUndeclared(string $declaredType): bool
    {
        return \in_array($declaredType, self::UNDECLARED_TYPES, true);
    }

    public static function declaredOrSniffed(string $declaredType, string $bytes): string
    {
        if (!self::isUndeclared($declaredType)) {
            return $declaredType;
        }

        return ImageMagicBytes::typeOf($bytes) ?? $declaredType;
    }

    private function __construct()
    {
    }
}
