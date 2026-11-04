<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\SendTestDigestRequest;
use App\Dto\Me\UpdateDigestRequest;
use App\Entity\User;
use App\Http\MeProfileJson;
use App\Service\Account\AccountPreferencesWriter;
use App\Service\Mail\Digest\SendTestDigest;
use App\Service\Mail\Digest\TestDigestEligibility;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class DigestSettingsController
{
    public function __construct(
        private AccountPreferencesWriter $preferences,
        private SendTestDigest $sendTestDigest,
        private TestDigestEligibility $testDigestEligibility,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $digestTestLimiter,
        private MeProfileJson $profile,
    ) {
    }

    /** Its own PATCH for the reason ReaderPreferencesController::updatePreferences() gives. */
    #[Route('/api/me/digest', name: 'api_me_update_digest', methods: ['PATCH'])]
    public function updateDigest(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateDigestRequest $request,
    ): JsonResponse {
        $this->preferences->changeDigest($user, $request->toConfiguration());

        return new JsonResponse($this->profile->of($user));
    }

    #[Route('/api/me/digest/test', name: 'api_me_digest_test', methods: ['POST'])]
    public function sendTestDigest(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SendTestDigestRequest $request,
    ): JsonResponse {
        $this->testDigestEligibility->assertEligible($user);
        $this->rateLimitGuard->enforceForUser($this->digestTestLimiter, $user);
        $sent = $this->sendTestDigest->send($user, $request->days);

        return new JsonResponse(['sent' => $sent]);
    }
}
