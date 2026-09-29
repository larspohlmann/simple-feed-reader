<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ForYouSweepReportJson;
use App\Http\MaintenanceTickJson;
use App\Http\MaintenanceTokenGuard;
use App\Http\RefreshReportJson;
use App\Service\Maintenance\MaintenanceTick;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Refresh\Model\RefreshReportModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Cron-facing actions, authenticated by MaintenanceTokenGuard's shared token instead of a JWT. */
final readonly class MaintenanceController
{
    public function __construct(
        private MaintenanceTokenGuard $tokenGuard,
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private MaintenanceTick $maintenanceTick,
    ) {
    }

    #[Route('/maintenance/refresh', name: 'maintenance_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $rejection = $this->tokenGuard->rejectionResponse($request);
        if (null !== $rejection) {
            return $rejection;
        }

        $report = $this->refreshRunner->run(RefreshRequestModel::allDue(MaintenanceTick::REFRESH_BUDGET_SECONDS));

        $status = match ($report->status) {
            'busy' => Response::HTTP_CONFLICT,
            RefreshReportModel::STATUS_ABORTED => Response::HTTP_INTERNAL_SERVER_ERROR,
            default => Response::HTTP_OK,
        };

        return new JsonResponse(RefreshReportJson::report($report), $status);
    }

    /**
     * Starts the due accounts and advances every active run once, so an install without the worker can drive
     * scheduled generation from cron. One tick per run keeps the request bounded.
     */
    #[Route('/maintenance/recommendations/sweep', name: 'maintenance_recommendations_sweep', methods: ['POST'])]
    public function sweepRecommendations(Request $request): JsonResponse
    {
        $rejection = $this->tokenGuard->rejectionResponse($request);
        if (null !== $rejection) {
            return $rejection;
        }

        return new JsonResponse(ForYouSweepReportJson::report($this->forYouSweep->sweepOnce()));
    }

    /**
     * Runs refresh, then every sweep, from one cron line. Each half reports its own
     * outcome as status — a refresh that came back busy or aborted still answers 200 —
     * while /maintenance/refresh keeps its 409/500 mapping for a caller that pings it alone.
     */
    #[Route('/maintenance/tick', name: 'maintenance_tick', methods: ['POST'])]
    public function tick(Request $request): JsonResponse
    {
        $rejection = $this->tokenGuard->rejectionResponse($request);
        if (null !== $rejection) {
            return $rejection;
        }

        return new JsonResponse(MaintenanceTickJson::report($this->maintenanceTick->run()));
    }
}
