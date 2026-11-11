<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Enum\MagazineStyle;
use App\Repository\DigestSettingsRepository;
use App\Repository\FeedDirectoryScopeRepository;
use App\Repository\ReadingSettingsRepository;
use App\Repository\SharingSettingsRepository;
use App\Service\Account\Model\SharingSettingsModel;
use App\Service\FeedDirectory\FeedDirectoryFiles;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use App\Service\Mail\Digest\DigestEnablement;
use App\Service\Mail\Digest\Model\DigestConfigurationModel;
use App\Service\Passkey\PasskeyOffer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountPreferencesWriter
{
    public function __construct(
        private DigestEnablement $digestEnablement,
        private PasskeyOffer $passkeyOffer,
        private ReadingSettingsRepository $readingSettings,
        private SharingSettingsRepository $sharingSettings,
        private DigestSettingsRepository $digestSettings,
        private FeedDirectoryScopeRepository $feedDirectoryScopes,
        private FeedDirectoryFiles $feedDirectoryFiles,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function changeLocale(User $user, string $locale): void
    {
        $user->setLocale($locale);
        $this->entityManager->flush();
    }

    public function changeScrapeFallback(User $user, bool $scrapeFallbackEnabled): void
    {
        $this->readingSettings->forUser($user)->setScrapeFallbackEnabled($scrapeFallbackEnabled);
        $this->entityManager->flush();
    }

    public function changeMagazineStyle(User $user, MagazineStyle $magazineStyle): void
    {
        $this->readingSettings->forUser($user)->setMagazineStyle($magazineStyle);
        $this->entityManager->flush();
    }

    public function changeSharing(User $user, SharingSettingsModel $sharing): void
    {
        $settings = $this->sharingSettings->forUser($user);
        $settings->setEnabled($sharing->enabled);
        $settings->setHiddenTargets($sharing->hiddenTargets);
        $settings->setRememberingShares($sharing->rememberShares);
        $this->entityManager->flush();
    }

    public function changeTrending(User $user, bool $trendingEnabled): void
    {
        $this->readingSettings->forUser($user)->setTrendingEnabled($trendingEnabled);
        $this->entityManager->flush();
    }

    public function changeFeedDirectoryScope(User $user, FeedDirectoryScopeModel $scope): void
    {
        $this->feedDirectoryFiles->requireKnown($scope);
        $this->feedDirectoryScopes->forUser($user)->change($scope->countries, $scope->languages);
        $this->entityManager->flush();
    }

    public function changeDigest(User $user, DigestConfigurationModel $configuration): void
    {
        $this->digestEnablement->applyTo($this->digestSettings->forUser($user), $configuration);
        $this->entityManager->flush();
    }

    public function answerPasskeyOffer(User $user): void
    {
        $this->passkeyOffer->markAnswered($user);
        $this->entityManager->flush();
    }
}
