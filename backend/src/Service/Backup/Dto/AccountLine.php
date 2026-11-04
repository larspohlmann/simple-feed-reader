<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Enum\MagazineStyle;
use App\Enum\SocialNetwork;
use App\Service\Backup\Support\LineField;
use App\Service\Backup\Support\LineFieldWithDefault;

/**
 * The account's own settings, exactly once per backup.
 */
final readonly class AccountLine
{
    /**
     * @param list<SocialNetwork> $savedSearchIncludedNetworks
     * @param list<SocialNetwork> $forYouExcludedNetworks
     * @param list<string>        $hiddenShareTargets
     */
    public function __construct(
        public string $locale,
        public bool $scrapeFallbackEnabled,
        public MagazineStyle $magazineStyle,
        public bool $sharingEnabled,
        public array $hiddenShareTargets,
        public bool $rememberShares,
        public bool $trendingEnabled,
        public array $savedSearchIncludedNetworks,
        public array $forYouExcludedNetworks,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            locale: LineField::string($line, 'locale'),
            scrapeFallbackEnabled: LineField::bool($line, 'scrapeFallbackEnabled'),
            // An unknown style falls back to default: restore over reject.
            magazineStyle: MagazineStyle::tryFrom(
                LineFieldWithDefault::string($line, 'magazineStyle', MagazineStyle::Boxed->value),
            ) ?? MagazineStyle::Boxed,
            sharingEnabled: LineFieldWithDefault::bool($line, 'sharingEnabled', true),
            hiddenShareTargets: LineFieldWithDefault::stringList($line, 'hiddenShareTargets'),
            rememberShares: LineFieldWithDefault::bool($line, 'rememberShares', true),
            trendingEnabled: LineFieldWithDefault::bool($line, 'trendingEnabled', true),
            savedSearchIncludedNetworks: self::networks($line, 'savedSearchIncludedNetworks'),
            forYouExcludedNetworks: self::networks($line, 'forYouExcludedNetworks'),
        );
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return list<SocialNetwork>
     */
    private static function networks(array $line, string $key): array
    {
        return array_values(array_filter(array_map(
            SocialNetwork::tryFrom(...),
            array_unique(LineFieldWithDefault::stringList($line, $key)),
        )));
    }
}
