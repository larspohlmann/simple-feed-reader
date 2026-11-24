<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Dto;

use App\Entity\User;
use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use App\Service\Identity\Account\Model\AccountSettingsModel;
use App\Service\Operations\Backup\Model\AccountNetworkSwitchesModel;
use App\Service\Operations\Backup\Model\ReadingSettingsLineModel;
use App\Service\Operations\Backup\Support\BackupLineFormat;
use App\Service\Operations\Backup\Support\BackupSchema;
use App\Service\Operations\Backup\Support\LineField;
use App\Service\Operations\Backup\Support\LineFieldWithDefault;
use App\Service\Sourcing\FeedDirectory\Model\FeedDirectoryScopeModel;

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
        public ReadingSettingsLineModel $reading,
        public bool $sharingEnabled,
        public array $hiddenShareTargets,
        public bool $rememberShares,
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
            reading: ReadingSettingsLineModel::fromLine($line),
            sharingEnabled: LineFieldWithDefault::bool($line, 'sharingEnabled', true),
            hiddenShareTargets: LineFieldWithDefault::stringList($line, 'hiddenShareTargets'),
            rememberShares: LineFieldWithDefault::bool($line, 'rememberShares', true),
            feedDirectoryScope: self::feedDirectoryScope(LineField::objectOrNull($line, 'feedDirectoryScope') ?? []),
            networks: new AccountNetworkSwitchesModel(
                self::networks($line, 'savedSearchIncludedNetworks'),
                self::excludedNetworks($line),
            ),
        );
    }

    public static function fromAccount(
        User $user,
        AccountSettingsModel $settings,
        AccountNetworkSwitchesModel $networks,
    ): self {
        return new self(
            locale: $user->getLocale(),
            reading: ReadingSettingsLineModel::fromSettings($settings->reading),
            sharingEnabled: $settings->sharing->isEnabled(),
            hiddenShareTargets: $settings->sharing->getHiddenTargets(),
            rememberShares: $settings->sharing->isRememberingShares(),
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
            'scrapeFallbackEnabled' => $this->reading->scrapeFallbackEnabled,
            'magazineStyle' => $this->reading->magazineStyle->value,
            'sidebarStyle' => $this->reading->sidebarStyle->value,
            'theme' => $this->reading->theme->value,
            'handedness' => $this->reading->handedness->value,
            'sharingEnabled' => $this->sharingEnabled,
            'hiddenShareTargets' => $this->hiddenShareTargets,
            'rememberShares' => $this->rememberShares,
            'trendingEnabled' => $this->reading->trendingEnabled,
            'feedDirectoryScope' => [
                'countries' => $this->feedDirectoryScope->countries,
                'languages' => $this->feedDirectoryScope->languages,
            ],
            'savedSearchIncludedNetworks' => SocialNetwork::toValues($this->networks->savedSearchIncludedNetworks),
        ], ...array_map(
            fn (InclusionSurface $surface): array => SocialNetwork::toValues($this->networks->excludedFrom($surface)),
            self::EXCLUDED_NETWORKS_KEYS,
        )]);
    }

    /** @param array<string, mixed> $fields */
    private static function feedDirectoryScope(array $fields): FeedDirectoryScopeModel
    {
        return FeedDirectoryScopeModel::fromListsOrEmpty(
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
        return array_values(array_filter(
            array_map(SocialNetwork::tryFrom(...), array_unique(LineFieldWithDefault::stringList($line, $key))),
        ));
    }
}
