<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

enum DigestImageKind
{
    case Thumbnail;
    case Favicon;

    public function contentType(): string
    {
        return match ($this) {
            self::Thumbnail => 'image/jpeg',
            self::Favicon => 'image/png',
        };
    }
}
