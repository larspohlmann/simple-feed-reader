<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\VersionJson;
use App\Service\Version\VersionReporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Which build the API is running, and whether a newer release exists upstream.
 * The SPA carries its own version baked in at build time and compares the two:
 * when they differ, the browser is holding a cached bundle from an earlier
 * release. `latest`/`updateAvailable` drive the sidebar's update badge; both
 * fall silent (null / false) whenever the upstream check has nothing to report.
 */
final class VersionController
{
    #[Route('/api/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(VersionReporter $reporter): JsonResponse
    {
        return new JsonResponse(VersionJson::of($reporter->report()));
    }
}
