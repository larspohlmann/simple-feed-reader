<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Account\AccountPreferencesWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Records the answer to the one-time passkey offer. Not a field on UpdatePreferencesRequest: an answer that can
 * arrive unset must never be indistinguishable from one the user set.
 */
final readonly class PasskeyOfferController
{
    public function __construct(
        private AccountPreferencesWriter $preferences,
    ) {
    }

    #[Route('/api/me/passkey-offer/answer', name: 'api_me_passkey_offer_answer', methods: ['POST'])]
    public function answer(#[CurrentUser] User $user): JsonResponse
    {
        $this->preferences->answerPasskeyOffer($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
