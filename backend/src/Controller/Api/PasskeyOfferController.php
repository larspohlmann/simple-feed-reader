<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Passkey\PasskeyOffer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Records the answer to the one-time passkey enrolment offer (#624). Its own
 * controller, not an action on MeController: that controller's constructor
 * already carries eight dependencies, and — as with the locale PATCH there —
 * this write is independent of the rest of the preferences payload. It is
 * deliberately not a field on UpdatePreferencesRequest either, for the same
 * reason that DTO's docblock gives for scrapeFallbackEnabled: a value that
 * can arrive unset must never be indistinguishable from one the user set.
 */
final readonly class PasskeyOfferController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PasskeyOffer $passkeyOffer,
    ) {
    }

    #[Route('/api/me/passkey-offer/answer', name: 'api_me_passkey_offer_answer', methods: ['POST'])]
    public function answer(#[CurrentUser] User $user): JsonResponse
    {
        $this->passkeyOffer->markAnswered($user);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
