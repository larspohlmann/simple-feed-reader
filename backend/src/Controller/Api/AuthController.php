<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Auth\RegisterRequest;
use App\Dto\Auth\VerifyEmailRequest;
use App\Exception\InvalidTokenException;
use App\Exception\ValidationException;
use App\Service\Auth\AltchaService;
use App\Service\Auth\RegistrationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly AltchaService $altcha,
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

    #[Route('/altcha-challenge', name: 'api_auth_altcha_challenge', methods: ['GET'])]
    public function altchaChallenge(): JsonResponse
    {
        return new JsonResponse($this->altcha->createChallenge()->toArray());
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
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
        if (!$this->registration->verifyEmail($request->token)) {
            throw new InvalidTokenException();
        }

        return new JsonResponse(['status' => 'pending_approval']);
    }
}
