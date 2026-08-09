<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Maintenance\MaintenanceTokenGuard;
use App\Service\Recommendation\ForYouSweep;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
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
final readonly class MaintenanceController
{
    private const int REFRESH_BUDGET_SECONDS = 20;

    public function __construct(
        private MaintenanceTokenGuard $tokenGuard,
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
    ) {
    }

    #[Route('/maintenance/refresh', name: 'maintenance_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        if (!$this->tokenGuard->isAuthorized($request)) {
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

    /**
     * Starts the accounts that are due and advances every active run once, so
     * an install without the background worker can drive scheduled generation
     * from an external cron (#333). One tick per run keeps the request bounded.
     */
    #[Route('/maintenance/recommendations/sweep', name: 'maintenance_recommendations_sweep', methods: ['POST'])]
    public function sweepRecommendations(Request $request): JsonResponse
    {
        if (!$this->tokenGuard->isAuthorized($request)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->forYouSweep->sweepOnce()->toArray());
    }
}
