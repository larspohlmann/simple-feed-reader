<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Version\ReleaseVersionReader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Which build the API is running. The SPA carries its own version baked in at
 * build time and compares the two: when they differ, the browser is holding a
 * cached bundle from an earlier release.
 */
final class VersionController
{
    #[Route('/api/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(ReleaseVersionReader $reader): JsonResponse
    {
        $release = $reader->read();

        return new JsonResponse([
            'version' => $release->version,
            'commit' => $release->commit,
            'builtAt' => $release->builtAt,
        ]);
    }
}
