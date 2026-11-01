<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\VersionJson;
use App\Service\Version\VersionReporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/version')]
final readonly class AdminVersionController
{
    public function __construct(private VersionReporter $reporter)
    {
    }

    #[Route('/check', name: 'api_admin_version_check', methods: ['POST'])]
    public function check(): JsonResponse
    {
        return new JsonResponse(VersionJson::of($this->reporter->freshReport()));
    }
}
