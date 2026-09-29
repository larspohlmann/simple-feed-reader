<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\VersionJson;
use App\Service\Version\VersionReporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** The running build and newest upstream release; the SPA compares `version` with its own to spot a stale bundle. */
final readonly class VersionController
{
    #[Route('/api/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(VersionReporter $reporter): JsonResponse
    {
        return new JsonResponse(VersionJson::of($reporter->report()));
    }
}
