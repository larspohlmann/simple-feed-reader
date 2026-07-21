<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController
{
    /**
     * Never executed: the json_login listener intercepts the request and the
     * success/failure handlers write the response. The route exists so the
     * firewall's check_path resolves and so a GET returns 405, not 404.
     */
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('Handled by the json_login listener.');
    }
}
