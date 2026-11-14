<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

final class MediaType
{
    public static function of(?string $contentTypeHeader): string
    {
        return mb_strtolower(trim(explode(';', $contentTypeHeader ?? '')[0]));
    }

    private function __construct()
    {
    }
}
