<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Service\Mail\Digest\DigestConfiguration;
use App\Service\Mail\Digest\DigestEnablement;
use App\Service\Passkey\PasskeyOffer;
use App\Service\Reader\MagazineStyle;
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

    public function changeDigest(User $user, DigestConfiguration $configuration): void
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
