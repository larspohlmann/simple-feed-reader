<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dto\Me\UpdateDigestRequest;
use App\Dto\Me\UpdateLocaleRequest;
use App\Dto\Me\UpdateMagazineStyleRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Entity\User;
use App\Service\Mail\Digest\DigestEnablement;
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

    public function changeLocale(User $user, UpdateLocaleRequest $request): void
    {
        $user->setLocale($request->locale);
        $this->entityManager->flush();
    }

    public function changeScrapeFallback(User $user, UpdatePreferencesRequest $request): void
    {
        $user->getPreferences()->setScrapeFallbackEnabled($request->scrapeFallbackEnabled);
        $this->entityManager->flush();
    }

    public function changeMagazineStyle(User $user, UpdateMagazineStyleRequest $request): void
    {
        $user->getPreferences()->setMagazineStyle($request->magazineStyle);
        $this->entityManager->flush();
    }

    public function changeDigest(User $user, UpdateDigestRequest $request): void
    {
        $this->digestEnablement->applyTo($user->getPreferences(), $request);
        $this->entityManager->flush();
    }

    public function answerPasskeyOffer(User $user): void
    {
        $this->passkeyOffer->markAnswered($user);
        $this->entityManager->flush();
    }
}
