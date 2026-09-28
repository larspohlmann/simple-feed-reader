<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FetchResponse;
use App\Service\Refresh\MissingFaviconResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

final class MissingFaviconResolverTest extends DbTestCase
{
    use ReloadsEntities;

    private StubFeedFetcher $homepages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homepages = new StubFeedFetcher();
    }

    public function testEachFeedWithoutAnIconGetsTheOneItsSiteAdvertises(): void
    {
        $blog = new Feed('https://feeds.example.net/blog');
        $blog->setSiteUrl('https://blog.example.com/');
        $plain = new Feed('https://plain.example.com/feed');
        $known = new Feed('https://known.example.com/feed');
        $known->setFaviconUrl('https://known.example.com/known.png');
        $this->persistAll($blog, $plain, $known);
        $this->homepageAdvertises('https://blog.example.com', '/blog.png');
        $this->homepageAdvertises('https://plain.example.com', '/plain.png');

        $this->resolver($this->em)->resolveFor([$blog, $plain, $known]);

        self::assertSame(['https://blog.example.com', 'https://plain.example.com'], $this->homepages->fetchedUrls);
        self::assertSame('https://blog.example.com/blog.png', $this->reload($blog)->getFaviconUrl());
        self::assertSame('https://plain.example.com/plain.png', $this->reload($plain)->getFaviconUrl());
        self::assertSame('https://known.example.com/known.png', $this->reload($known)->getFaviconUrl());
    }

    public function testFeedsThatAlreadyHaveAnIconCostNoFetchAndNoFlush(): void
    {
        $known = new Feed('https://known.example.com/feed');
        $known->setFaviconUrl('https://known.example.com/known.png');
        $this->persistAll($known);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->resolver($em)->resolveFor([$known]);

        self::assertSame([], $this->homepages->fetchedUrls);
    }

    private function resolver(EntityManagerInterface $em): MissingFaviconResolver
    {
        return new MissingFaviconResolver(new FaviconResolver($this->homepages, new NullLogger()), $em);
    }

    private function persistAll(Feed ...$feeds): void
    {
        foreach ($feeds as $feed) {
            $this->em->persist($feed);
        }
        $this->em->flush();
    }

    private function homepageAdvertises(string $origin, string $iconPath): void
    {
        $this->homepages->willReturn($origin, FetchResponse::fetched(
            $origin . '/',
            false,
            sprintf(/** @lang TEXT */ '<link rel="icon" href="%s">', $iconPath),
            null,
            null,
        ));
    }
}
