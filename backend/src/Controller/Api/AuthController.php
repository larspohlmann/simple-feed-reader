<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Auth\PasswordResetConfirmRequest;
use App\Dto\Auth\PasswordResetRequest;
use App\Dto\Auth\RegisterRequest;
use App\Dto\Auth\VerifyEmailRequest;
use App\Exception\InvalidTokenException;
use App\Exception\ValidationException;
use App\Service\Auth\AltchaService;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Auth\RegistrationService;
use App\Service\RateLimit\RateLimitGuard;
use Psr\Cache\InvalidArgumentException;
use Random\RandomException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final readonly class AuthController
{
    public function __construct(
        private RegistrationService $registration,
        private RegistrationPolicy $policy,
        private AltchaService $altcha,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $registrationLimiter,
        private RateLimiterFactoryInterface $passwordResetRequestLimiter,
    ) {
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

    /**
     * @throws RandomException
     */
    #[Route('/altcha-challenge', name: 'api_auth_altcha_challenge', methods: ['GET'])]
    public function altchaChallenge(): JsonResponse
    {
        return new JsonResponse($this->altcha->createChallenge()->toArray());
    }

    /**
     * @throws RandomException
     * @throws TransportExceptionInterface
     * @throws InvalidArgumentException
     */
    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $request, Request $httpRequest): JsonResponse
    {
        // Limit before the ALTCHA check, not after: the cap is on requests, not
        // successes. Capping only accepted solutions would leave an attacker free
        // to hammer the endpoint with junk, and — worse — would make the limiter
        // an oracle, since only requests that reached the mailer would count.
        $this->rateLimitGuard->enforceForClient($this->registrationLimiter, $httpRequest);

        if (!$this->altcha->verify($request->altcha)) {
            throw new ValidationException(['altcha' => ['The anti-spam challenge was not solved correctly.']]);
        }

        $this->registration->register($request->email, $request->password, $request->locale);

        // The status a new signup receives under the current policy. Instance-
        // wide and identical for a duplicate address, so it never becomes an
        // existence oracle. 202: the account may still need verification or
        // approval before it can log in.
        return new JsonResponse(
            ['status' => $this->policy->prospectiveStatusForEmailSignup()->value],
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

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     * @throws InvalidArgumentException
     */
    #[Route('/password-reset-request', name: 'api_auth_password_reset_request', methods: ['POST'])]
    public function passwordResetRequest(
        #[MapRequestPayload] PasswordResetRequest $request,
        Request $httpRequest,
    ): JsonResponse {
        // Limit before the ALTCHA check, for the same oracle-avoidance reason as
        // register().
        $this->rateLimitGuard->enforceForClient($this->passwordResetRequestLimiter, $httpRequest);

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
