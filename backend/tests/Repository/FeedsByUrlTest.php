<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;

/**
 * The restore's one feed lookup for a whole file (#455).
 */
final class FeedsByUrlTest extends DbTestCase
{
    public function testReturnsOnlyTheAskedUrlsIndexedByUrl(): void
    {
        $one = new Feed('https://one.example/feed.xml');
        $this->em->persist($one);
        $this->em->persist(new Feed('https://two.example/feed.xml'));
        $this->em->flush();

        $byUrl = $this->repository()->findByUrlsIndexedByUrl([
            'https://one.example/feed.xml',
            'https://never.example/feed.xml',
        ]);

        self::assertSame(['https://one.example/feed.xml' => $one], $byUrl);
    }

    public function testAnEmptyListAsksForNothing(): void
    {
        self::assertSame([], $this->repository()->findByUrlsIndexedByUrl([]));
    }

    private function repository(): FeedRepository
    {
        $repository = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
