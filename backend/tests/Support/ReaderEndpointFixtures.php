<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

trait ReaderEndpointFixtures
{
    // The reader limiter counts in a FILESYSTEM pool that outlives the run, so a prior case's spend would trip a 429.
    protected function clearReaderRateLimiter(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    private function spendReaderBudget(User $user): void
    {
        $readerLimiter = self::getContainer()->get('limiter.reader');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $readerLimiter);
        $readerLimiter->create('user-' . $user->requireId())->consume(60);
    }

    private function installFake(): FakeArticleExtractor
    {
        $fake = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $fake);

        return $fake;
    }
}
