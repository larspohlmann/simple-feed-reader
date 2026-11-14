<?php

declare(strict_types=1);

namespace App\Enum;

enum FeedLanguage: string
{
    case German = 'de';
    case English = 'en';
    case French = 'fr';
    case Spanish = 'es';

    public static function fromTag(?string $tag): ?self
    {
        if (null === $tag) {
            return null;
        }

        $primarySubtag = preg_split('/[-_]/', strtolower(trim($tag)), 2);

        return self::tryFrom($primarySubtag[0] ?? '');
    }
}
