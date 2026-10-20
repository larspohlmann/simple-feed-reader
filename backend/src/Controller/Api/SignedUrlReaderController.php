<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReaderJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\ReaderUrlSignature;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api')]
final readonly class SignedUrlReaderController
{
    public function __construct(
        private ReaderUrlSignature $signature,
        private ClockInterface $clock,
        private ArticleExtractorInterface $extractor,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $readerLimiter,
    ) {
    }

    #[Route('/reader', name: 'api_reader', methods: ['GET'])]
    public function reader(
        #[MapQueryParameter] string $url,
        #[MapQueryParameter] string $token,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->signature->verify($url, $token);
        $this->rateLimitGuard->enforceForUser($this->readerLimiter, $user);

        return new JsonResponse(ReaderJson::one($this->extractor->extract($url), null, $this->clock->now()));
    }
}
