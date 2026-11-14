<?php

declare(strict_types=1);

namespace App\Service\Identity\Account;

use App\Entity\ReadingSettingsChange;
use App\Entity\User;
use App\Repository\DigestSettingsRepository;
use App\Repository\FeedDirectoryScopeRepository;
use App\Repository\ReadingSettingsRepository;
use App\Repository\SharingSettingsRepository;
use App\Service\Identity\Account\Model\SharingSettingsModel;
use App\Service\Sourcing\FeedDirectory\FeedDirectoryFiles;
use App\Service\Sourcing\FeedDirectory\Model\FeedDirectoryScopeModel;
use App\Service\Outbound\Mail\Digest\DigestEnablement;
use App\Service\Outbound\Mail\Digest\Model\DigestConfigurationModel;
use App\Service\Identity\Passkey\PasskeyOffer;
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

    public function changeReadingSettings(User $user, ReadingSettingsChange $change): void
    {
        $this->readingSettings->forUser($user)->apply($change);
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
