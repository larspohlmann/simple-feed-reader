<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum SocialNetwork: string
{
    use RequestValueParsing;

    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';

    /**
     * @throws ValidationException when the value names no network
     */
    public static function fromRequestValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::caseOrRefusal($value, 'network');
    }

    /**
     * @param list<self> $networks
     *
     * @return list<string>
     */
    public static function valuesOf(array $networks): array
    {
        return array_map(static fn (self $network): string => $network->value, $networks);
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Bluesky => 'Bluesky',
            self::Mastodon => 'Mastodon',
        };
    }
}
