<?php

declare(strict_types=1);

namespace App\Enum;

enum FeedLanguage: string
{
    case German = 'de';
    case English = 'en';
    case French = 'fr';
    case Spanish = 'es';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $language): string => $language->value, self::cases());
    }

    public function englishName(): string
    {
        return match ($this) {
            self::German => 'German',
            self::English => 'English',
            self::French => 'French',
            self::Spanish => 'Spanish',
        };
    }

    public static function fromTag(?string $tag): ?self
    {
        if (null === $tag) {
            return null;
        }

        $primarySubtag = preg_split('/[-_]/', strtolower(trim($tag)), 2);

        return self::tryFrom($primarySubtag[0] ?? '');
    }
}
