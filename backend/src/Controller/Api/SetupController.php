<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Setup\SetupAdminRequest;
use App\Repository\UserRepository;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Auth\WebAdminSetup;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/setup')]
final readonly class SetupController
{
    public function __construct(
        private UserRepository $users,
        private WebAdminSetup $setup,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $setupLimiter,
        private RegistrationPolicy $policy,
    ) {
    }

    #[Route('/status', name: 'api_setup_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse([
            'needsSetup' => !$this->users->hasAnyAdmin(),
            'mailEnabled' => $this->policy->mailEnabled(),
        ]);
    }

    #[Route('/admin', name: 'api_setup_admin', methods: ['POST'])]
    public function createAdmin(#[MapRequestPayload] SetupAdminRequest $request, Request $httpRequest): JsonResponse
    {
        $this->rateLimitGuard->enforceForClient($this->setupLimiter, $httpRequest);

        $token = $this->setup->createFirstAdmin($request->email, $request->password, $request->secret);

        return new JsonResponse(['token' => $token], Response::HTTP_CREATED);
    }
}
