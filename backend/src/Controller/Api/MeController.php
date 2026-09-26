<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\SendTestDigestRequest;
use App\Dto\Me\UpdateDigestRequest;
use App\Dto\Me\UpdateLocaleRequest;
use App\Dto\Me\UpdateMagazineStyleRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Entity\User;
use App\Http\MeJson;
use App\Service\Account\AccountDeleter;
use App\Service\Account\AccountPreferencesWriter;
use App\Service\Auth\RegistrationService;
use App\Service\Mail\Digest\SendTestDigest;
use App\Service\Mail\MailCapability;
use App\Service\RateLimit\MeRateLimiters;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The client's view of its own account. The response shape is hand-built in
 * {@see MeJson}, not serialised from the entity — see the note there.
 */
final readonly class MeController
{
    public function __construct(
        private AccountPreferencesWriter $preferences,
        private AccountDeleter $accountDeleter,
        private MailCapability $mail,
        private RegistrationService $registration,
        private SendTestDigest $sendTestDigest,
        private RateLimitGuard $rateLimitGuard,
        private MeRateLimiters $rateLimiters,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone));
    }

    /**
     * The server is the source of truth for the account's language: it is what
     * AccountMailer reads for every transactional email, unlike an SPA-only cache.
     */
    #[Route('/api/me', name: 'api_me_update_locale', methods: ['PATCH'])]
    public function updateLocale(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateLocaleRequest $request,
    ): JsonResponse {
        $this->preferences->changeLocale($user, $request);

        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone));
    }

    /**
     * Split from the locale PATCH because UpdateLocaleRequest requires a
     * non-blank locale, which would cost it its 422-on-unsupported-value
     * guarantee if preference writes had to resend the language too (#180).
     */
    #[Route('/api/me/preferences', name: 'api_me_update_preferences', methods: ['PATCH'])]
    public function updatePreferences(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdatePreferencesRequest $request,
    ): JsonResponse {
        $this->preferences->changeScrapeFallback($user, $request);

        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone));
    }

    /** Its own PATCH for the reason updatePreferences() gives (#723). */
    #[Route('/api/me/magazine-style', name: 'api_me_update_magazine_style', methods: ['PATCH'])]
    public function updateMagazineStyle(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateMagazineStyleRequest $request,
    ): JsonResponse {
        $this->preferences->changeMagazineStyle($user, $request);

        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone));
    }

    /**
     * Its own PATCH for the reason updatePreferences() gives (#636). First-enable
     * seeding of digestLastSentAt lives in DigestEnablement, not here.
     */
    #[Route('/api/me/digest', name: 'api_me_update_digest', methods: ['PATCH'])]
    public function updateDigest(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateDigestRequest $request,
    ): JsonResponse {
        $this->preferences->changeDigest($user, $request);

        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone));
    }

    /**
     * Sends a one-off preview digest over the last `days` days, without moving
     * digestLastSentAt (#636) — SendTestDigest composes and sends but never
     * touches the schedule watermark, so this button can be pressed any number
     * of times without disturbing the real digest cadence. Gated the same way
     * as the real send: mail must be on for this instance and the address must
     * be verified, or there is nowhere trustworthy to send the preview to.
     */
    #[Route('/api/me/digest/test', name: 'api_me_digest_test', methods: ['POST'])]
    public function sendTestDigest(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SendTestDigestRequest $request,
    ): JsonResponse {
        if (!$this->mail->isEnabled() || !$user->isEmailVerified()) {
            throw new AccessDeniedHttpException('Mail is unavailable for this account.');
        }

        $this->rateLimitGuard->enforceForUser($this->rateLimiters->digestTest, $user);
        $sent = $this->sendTestDigest->send($user, $request->days);

        return new JsonResponse(['sent' => $sent]);
    }

    /**
     * Reissues the address-verification mail for an account that has not yet
     * proved its address (#636). Idempotent: RegistrationService::resendVerification()
     * is a no-op once the address is verified.
     */
    #[Route('/api/me/resend-verification', name: 'api_me_resend_verification', methods: ['POST'])]
    public function resendVerification(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->rateLimiters->resendVerification, $user);
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
