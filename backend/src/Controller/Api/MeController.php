<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\UpdateLocaleRequest;
use App\Entity\User;
use App\Http\MeProfileJson;
use App\Service\Account\AccountDeleter;
use App\Service\Account\AccountPreferencesWriter;
use App\Service\Auth\RegistrationService;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class MeController
{
    public function __construct(
        private AccountPreferencesWriter $preferences,
        private AccountDeleter $accountDeleter,
        private RegistrationService $registration,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $resendVerificationLimiter,
        private MeProfileJson $profile,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->profile->of($user));
    }

    /**
     * The server is the source of truth for the account's language: it is what
     * AccountMailer reads for every email, and what a native client reads.
     */
    #[Route('/api/me', name: 'api_me_update_locale', methods: ['PATCH'])]
    public function updateLocale(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateLocaleRequest $request,
    ): JsonResponse {
        $this->preferences->changeLocale($user, $request->locale);

        return new JsonResponse($this->profile->of($user));
    }

    #[Route('/api/me/resend-verification', name: 'api_me_resend_verification', methods: ['POST'])]
    public function resendVerification(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->resendVerificationLimiter, $user);
        $this->registration->resendVerification($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Self-service hard deletion. The typed confirmation is a client concern and
     * deliberately not a request field: requiring one would put a browser-only
     * input in the API contract, which the native-iOS constraint forbids.
     */
    #[Route('/api/me', name: 'api_me_delete', methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user): JsonResponse
    {
        $this->accountDeleter->deleteSelf($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
