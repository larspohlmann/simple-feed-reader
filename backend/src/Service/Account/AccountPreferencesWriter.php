<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Enum\MagazineStyle;
use App\Service\Mail\Digest\DigestEnablement;
use App\Service\Mail\Digest\Model\DigestConfigurationModel;
use App\Service\Passkey\PasskeyOffer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountPreferencesWriter
{
    public function __construct(
        private DigestEnablement $digestEnablement,
        private PasskeyOffer $passkeyOffer,
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
        $user->getPreferences()->setScrapeFallbackEnabled($scrapeFallbackEnabled);
        $this->entityManager->flush();
    }

    public function changeMagazineStyle(User $user, MagazineStyle $magazineStyle): void
    {
        $user->getPreferences()->setMagazineStyle($magazineStyle);
        $this->entityManager->flush();
    }

    public function changeDigest(User $user, DigestConfigurationModel $configuration): void
    {
        $this->digestEnablement->applyTo($user->getPreferences(), $configuration);
        $this->entityManager->flush();
    }

    public function answerPasskeyOffer(User $user): void
    {
        $this->passkeyOffer->markAnswered($user);
        $this->entityManager->flush();
    }
}
