<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\ClientError\ClientErrorReportRequest;
use App\Entity\User;
use App\Service\ClientError\ClientErrorRecorder;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/client-errors', name: 'api_client_errors', methods: ['POST'])]
final readonly class ClientErrorController
{
    public function __construct(
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $clientErrorsLimiter,
        private ClientErrorRecorder $recorder,
    ) {
    }

    public function __invoke(
        #[MapRequestPayload] ClientErrorReportRequest $request,
        Request $httpRequest,
        #[CurrentUser] ?User $user,
    ): Response {
        $this->rateLimitGuard->enforceForClient($this->clientErrorsLimiter, $httpRequest);
        $this->recorder->record($request->errors, $user);

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
