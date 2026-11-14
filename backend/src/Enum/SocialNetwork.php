<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum SocialNetwork: string
{
    use RequestValueParsing;

    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';
    case Lemmy = 'lemmy';

    public const string FEED_NETWORK_REQUIREMENT = 'bluesky|mastodon';

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

    public static function tryFromFeed(?string $value): ?self
    {
        $network = null === $value ? null : self::tryFrom($value);

        return true === $network?->carriesFeeds() ? $network : null;
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

    /** @return list<self> the networks whose posts arrive as feeds */
    public static function feedNetworks(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $network): bool => $network->carriesFeeds()));
    }

    /** @return list<self> */
    private static function requestCases(): array
    {
        return self::feedNetworks();
    }

    public function carriesFeeds(): bool
    {
        return self::Lemmy !== $this;
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Bluesky => 'Bluesky',
            self::Mastodon => 'Mastodon',
            self::Lemmy => 'Lemmy',
        };
    }
}
