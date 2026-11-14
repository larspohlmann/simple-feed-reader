<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\User;
use App\Enum\Handedness;
use App\Enum\InclusionSurface;
use App\Enum\MagazineStyle;
use App\Enum\SocialNetwork;
use App\Service\Account\Model\AccountSettingsModel;
use App\Service\Backup\Model\AccountNetworkSwitchesModel;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;
use App\Service\Backup\Support\LineFieldWithDefault;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;

/**
 * The account's own settings, exactly once per backup.
 */
final readonly class AccountLine
{
    private const array EXCLUDED_NETWORKS_KEYS = [
        'forYouExcludedNetworks' => InclusionSurface::ForYou,
        'allItemsExcludedNetworks' => InclusionSurface::AllItems,
        'trendingExcludedNetworks' => InclusionSurface::Trending,
    ];

    /**
     * @param list<string> $hiddenShareTargets
     */
    public function __construct(
        public string $locale,
        public bool $scrapeFallbackEnabled,
        public MagazineStyle $magazineStyle,
        public Handedness $handedness,
        public bool $sharingEnabled,
        public array $hiddenShareTargets,
        public bool $rememberShares,
        public bool $trendingEnabled,
        public FeedDirectoryScopeModel $feedDirectoryScope,
        public AccountNetworkSwitchesModel $networks,
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
            handedness: Handedness::tryFrom(
                LineFieldWithDefault::string($line, 'handedness', Handedness::Right->value),
            ) ?? Handedness::Right,
            sharingEnabled: LineFieldWithDefault::bool($line, 'sharingEnabled', true),
            hiddenShareTargets: LineFieldWithDefault::stringList($line, 'hiddenShareTargets'),
            rememberShares: LineFieldWithDefault::bool($line, 'rememberShares', true),
            trendingEnabled: LineFieldWithDefault::bool($line, 'trendingEnabled', true),
            feedDirectoryScope: self::feedDirectoryScope(LineField::objectOrNull($line, 'feedDirectoryScope') ?? []),
            networks: new AccountNetworkSwitchesModel(
                self::networks($line, 'savedSearchIncludedNetworks'),
                self::excludedNetworks($line),
            ),
        );
    }

    public static function of(User $user, AccountSettingsModel $settings, AccountNetworkSwitchesModel $networks): self
    {
        return new self(
            locale: $user->getLocale(),
            scrapeFallbackEnabled: $settings->reading->isScrapeFallbackEnabled(),
            magazineStyle: $settings->reading->getMagazineStyle(),
            handedness: $settings->reading->getHandedness(),
            sharingEnabled: $settings->sharing->isEnabled(),
            hiddenShareTargets: $settings->sharing->getHiddenTargets(),
            rememberShares: $settings->sharing->isRememberingShares(),
            trendingEnabled: $settings->reading->isTrendingEnabled(),
            feedDirectoryScope: new FeedDirectoryScopeModel(
                $settings->feedDirectoryScope->getCountries(),
                $settings->feedDirectoryScope->getLanguages(),
            ),
            networks: $networks,
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([...[
            'kind' => BackupSchema::KIND_ACCOUNT,
            'locale' => $this->locale,
            'scrapeFallbackEnabled' => $this->scrapeFallbackEnabled,
            'magazineStyle' => $this->magazineStyle->value,
            'handedness' => $this->handedness->value,
            'sharingEnabled' => $this->sharingEnabled,
            'hiddenShareTargets' => $this->hiddenShareTargets,
            'rememberShares' => $this->rememberShares,
            'trendingEnabled' => $this->trendingEnabled,
            'feedDirectoryScope' => [
                'countries' => $this->feedDirectoryScope->countries,
                'languages' => $this->feedDirectoryScope->languages,
            ],
            'savedSearchIncludedNetworks' => SocialNetwork::valuesOf($this->networks->savedSearchIncludedNetworks),
        ], ...array_map(
            fn (InclusionSurface $surface): array => SocialNetwork::valuesOf($this->networks->excludedFrom($surface)),
            self::EXCLUDED_NETWORKS_KEYS,
        )]);
    }

    /** @param array<string, mixed> $fields */
    private static function feedDirectoryScope(array $fields): FeedDirectoryScopeModel
    {
        return FeedDirectoryScopeModel::orNone(
            LineFieldWithDefault::stringList($fields, 'countries'),
            LineFieldWithDefault::stringList($fields, 'languages'),
        );
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, list<SocialNetwork>>
     */
    private static function excludedNetworks(array $line): array
    {
        $excluded = [];
        foreach (self::EXCLUDED_NETWORKS_KEYS as $key => $surface) {
            $excluded[$surface->value] = self::networks($line, $key);
        }

        return $excluded;
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
