<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\Preferences;
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
            hiddenShareTargets: self::shareTargetIds($line),
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

    /**
     * @param array<string, mixed> $line
     *
     * @return list<string>
     */
    private static function shareTargetIds(array $line): array
    {
        return array_values(array_unique(array_filter(
            LineFieldWithDefault::stringList($line, 'hiddenShareTargets'),
            static fn (string $id): bool => preg_match(Preferences::TARGET_ID_PATTERN, $id) === 1,
        )));
    }
}
