<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Machine-facing maintenance actions, authenticated by a shared token
 * (constant-time comparison) instead of JWT. Called by the scheduled GitHub
 * Actions pinger or any external cron service — there is no crontab on the
 * production host.
 */
final class MaintenanceController
{
    private const REFRESH_BUDGET_SECONDS = 20;

    public function __construct(
        #[Autowire('%env(MAINTENANCE_TOKEN)%')]
        private readonly string $maintenanceToken,
        private readonly RefreshRunner $refreshRunner,
    ) {
    }

    #[Route('/maintenance/refresh', name: 'maintenance_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $report = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));

        $status = match ($report->status) {
            'busy' => Response::HTTP_CONFLICT,
            'aborted' => Response::HTTP_INTERNAL_SERVER_ERROR,
            default => Response::HTTP_OK,
        };

        return new JsonResponse($report->toArray(), $status);
    }

    private function isAuthorized(Request $request): bool
    {
        if ($this->maintenanceToken === '') {
            return false;
        }

        $provided = $request->query->get('token');

        return \is_string($provided) && hash_equals($this->maintenanceToken, $provided);
    }
}
