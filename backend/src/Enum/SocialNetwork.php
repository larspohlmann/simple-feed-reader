<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum SocialNetwork: string
{
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

        return self::tryFrom($value) ?? throw new ValidationException([
            'network' => ['Unknown network. Use one of: ' . self::valueList() . '.'],
        ]);
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Bluesky => 'Bluesky',
            self::Mastodon => 'Mastodon',
        };
    }

    private static function valueList(): string
    {
        return implode(', ', array_map(static fn (self $network): string => $network->value, self::cases()));
    }
}
