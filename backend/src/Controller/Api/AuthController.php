<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Auth\PasswordResetConfirmRequest;
use App\Dto\Auth\PasswordResetRequest;
use App\Dto\Auth\RegisterRequest;
use App\Dto\Auth\VerifyEmailRequest;
use App\Exception\InvalidTokenException;
use App\Exception\RateLimitedException;
use App\Exception\ValidationException;
use App\Service\Auth\AltchaService;
use App\Service\Auth\RegistrationService;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly AltchaService $altcha,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $registrationLimiter,
        private readonly RateLimiterFactoryInterface $passwordResetRequestLimiter,
    ) {
    }

    /**
     * Caps the two anonymous, email-sending endpoints per client IP.
     *
     * Called before the ALTCHA check, not after: the limit is on requests, not
     * on successes. Capping only accepted solutions would leave an attacker
     * free to hammer the endpoint with junk, and — worse — would make the
     * limiter itself an oracle, since only requests that got as far as the
     * mailer would count against a budget an attacker can probe.
     *
     * Note what the key is, and is not. getClientIp() returns REMOTE_ADDR
     * unless the request came from a trusted proxy, and nothing configures
     * trusted_proxies yet (deployment is Plan 6). That is the safe default —
     * a spoofed X-Forwarded-For cannot buy a fresh budget — but it means that
     * the day this app is put behind a CDN or reverse proxy, every request
     * arrives wearing the proxy's address and all callers share one bucket.
     * Whoever configures that proxy must set framework.trusted_proxies at the
     * same time, or this limiter silently becomes a global 5-per-15-minutes.
     */
    private function enforceLimit(RateLimiterFactoryInterface $limiter, Request $request): void
    {
        // A null IP (unusual, but possible for non-HTTP-ish transports) collapses
        // every such caller into one shared bucket. That fails closed, which is
        // the direction to fail in.
        $limit = $limiter->create($request->getClientIp())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        // The listener turns this into 429 problem+json with a Retry-After
        // header. max(1, ...) because a retryAfter that has just elapsed would
        // otherwise render as "Retry-After: 0", which clients read as "now".
        throw new RateLimitedException(max(
            1,
            $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
        ));
    }

    /**
     * Never executed: the json_login listener intercepts the request and the
     * success/failure handlers write the response. The route exists so the
     * firewall's check_path resolves and so a GET returns 405, not 404.
     */
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('Handled by the json_login listener.');
    }

    #[Route('/altcha-challenge', name: 'api_auth_altcha_challenge', methods: ['GET'])]
    public function altchaChallenge(): JsonResponse
    {
        return new JsonResponse($this->altcha->createChallenge()->toArray());
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $request, Request $httpRequest): JsonResponse
    {
        $this->enforceLimit($this->registrationLimiter, $httpRequest);

        if (!$this->altcha->verify($request->altcha)) {
            throw new ValidationException(['altcha' => ['The anti-spam challenge was not solved correctly.']]);
        }

        $this->registration->register($request->email, $request->password);

        // 202, not 201: the account exists but is not usable until verified
        // and approved. The body is identical for a duplicate address.
        return new JsonResponse(
            ['status' => 'pending_verification'],
            Response::HTTP_ACCEPTED,
        );
    }

    #[Route('/verify-email', name: 'api_auth_verify_email', methods: ['POST'])]
    public function verifyEmail(#[MapRequestPayload] VerifyEmailRequest $request): JsonResponse
    {
        $status = $this->registration->verifyEmail($request->token);

        if (null === $status) {
            throw new InvalidTokenException();
        }

        // The real status, not a hardcoded one: an account approved between the
        // mail going out and the link being clicked is already active, and
        // telling that user to wait would be simply false.
        return new JsonResponse(['status' => $status->value]);
    }

    #[Route('/password-reset-request', name: 'api_auth_password_reset_request', methods: ['POST'])]
    public function passwordResetRequest(
        #[MapRequestPayload] PasswordResetRequest $request,
        Request $httpRequest,
    ): JsonResponse {
        $this->enforceLimit($this->passwordResetRequestLimiter, $httpRequest);

        if (!$this->altcha->verify($request->altcha)) {
            throw new ValidationException(['altcha' => ['The anti-spam challenge was not solved correctly.']]);
        }

        $this->registration->requestPasswordReset($request->email);

        // Unconditionally "sent": whether an account exists is not public.
        return new JsonResponse(['status' => 'sent']);
    }

    #[Route('/password-reset', name: 'api_auth_password_reset', methods: ['POST'])]
    public function passwordReset(#[MapRequestPayload] PasswordResetConfirmRequest $request): JsonResponse
    {
        if (!$this->registration->resetPassword($request->token, $request->password)) {
            throw new InvalidTokenException();
        }

        return new JsonResponse(['status' => 'reset']);
    }
}
