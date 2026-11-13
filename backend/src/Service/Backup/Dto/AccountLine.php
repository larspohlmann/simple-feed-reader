<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\User;
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
    /**
     * @param list<string> $hiddenShareTargets
     */
    public function __construct(
        public string $locale,
        public bool $scrapeFallbackEnabled,
        public MagazineStyle $magazineStyle,
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
            sharingEnabled: LineFieldWithDefault::bool($line, 'sharingEnabled', true),
            hiddenShareTargets: LineFieldWithDefault::stringList($line, 'hiddenShareTargets'),
            rememberShares: LineFieldWithDefault::bool($line, 'rememberShares', true),
            trendingEnabled: LineFieldWithDefault::bool($line, 'trendingEnabled', true),
            feedDirectoryScope: self::feedDirectoryScope(LineField::objectOrNull($line, 'feedDirectoryScope') ?? []),
            networks: new AccountNetworkSwitchesModel(
                savedSearchIncludedNetworks: self::networks($line, 'savedSearchIncludedNetworks'),
                forYouExcludedNetworks: self::networks($line, 'forYouExcludedNetworks'),
                allItemsExcludedNetworks: self::networks($line, 'allItemsExcludedNetworks'),
                trendingExcludedNetworks: self::networks($line, 'trendingExcludedNetworks'),
            ),
        );
    }

    public static function of(User $user, AccountSettingsModel $settings, AccountNetworkSwitchesModel $networks): self
    {
        return new self(
            locale: $user->getLocale(),
            scrapeFallbackEnabled: $settings->reading->isScrapeFallbackEnabled(),
            magazineStyle: $settings->reading->getMagazineStyle(),
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
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ACCOUNT,
            'locale' => $this->locale,
            'scrapeFallbackEnabled' => $this->scrapeFallbackEnabled,
            'magazineStyle' => $this->magazineStyle->value,
            'sharingEnabled' => $this->sharingEnabled,
            'hiddenShareTargets' => $this->hiddenShareTargets,
            'rememberShares' => $this->rememberShares,
            'trendingEnabled' => $this->trendingEnabled,
            'feedDirectoryScope' => [
                'countries' => $this->feedDirectoryScope->countries,
                'languages' => $this->feedDirectoryScope->languages,
            ],
            'savedSearchIncludedNetworks' => SocialNetwork::valuesOf($this->networks->savedSearchIncludedNetworks),
            'forYouExcludedNetworks' => SocialNetwork::valuesOf($this->networks->forYouExcludedNetworks),
            'allItemsExcludedNetworks' => SocialNetwork::valuesOf($this->networks->allItemsExcludedNetworks),
            'trendingExcludedNetworks' => SocialNetwork::valuesOf($this->networks->trendingExcludedNetworks),
        ]);
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
