<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ProxiedImageResponse;
use App\Service\Image\ImageProxy\ImageProxyInterface;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ImageProxyController
{
    public function __construct(
        private ImageProxyInterface $imageProxy,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $imageProxyLimiter,
    ) {
    }

    #[Route('/api/image-proxy', name: 'api_image_proxy', methods: ['GET'])]
    public function image(#[MapQueryParameter] string $url, #[CurrentUser] User $user): Response
    {
        $this->rateLimitGuard->enforceForUser($this->imageProxyLimiter, $user);

        return ProxiedImageResponse::of($this->imageProxy->fetch($url));
    }
}
