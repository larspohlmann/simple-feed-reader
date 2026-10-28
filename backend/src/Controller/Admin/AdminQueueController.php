<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Admin\QueueDepthsJson;
use App\Service\Queue\QueueDepths;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/queues')]
final readonly class AdminQueueController
{
    public function __construct(private QueueDepths $depths)
    {
    }

    #[Route('', name: 'api_admin_queues', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(QueueDepthsJson::from($this->depths->all()));
    }
}
