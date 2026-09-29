<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\OwnedTagsCache;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OwnedTagsCacheTest extends KernelTestCase
{
    use SeedsUsers;

    private EntityManagerInterface $entityManager;
    private OwnedTagsCache $cache;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $cache = self::getContainer()->get(OwnedTagsCache::class);
        self::assertInstanceOf(OwnedTagsCache::class, $cache);
        $this->cache = $cache;
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->entityManager->persist($tag);

        return $tag;
    }

    public function testResolvesEveryOwnedId(): void
    {
        $user = $this->user('cache-owner@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->entityManager->flush();

        $resolved = $this->cache->findAllByIdsForUser(
            $user->requireId(),
            [$news->requireId(), $tech->requireId()],
        );

        self::assertCount(2, $resolved);
    }

    public function testDropsAnIdBelongingToAnotherUser(): void
    {
        $mine = $this->user('cache-mine@example.com');
        $theirs = $this->user('cache-theirs@example.com');
        $foreignTag = $this->tag($theirs, 'Theirs');
        $this->entityManager->flush();

        $resolved = $this->cache->findAllByIdsForUser($mine->requireId(), [$foreignTag->requireId()]);

        self::assertSame([], $resolved);
    }

    public function testDropsAnIdThatDoesNotExist(): void
    {
        $user = $this->user('cache-missing@example.com');
        $this->entityManager->flush();

        $resolved = $this->cache->findAllByIdsForUser($user->requireId(), [999_999]);

        self::assertSame([], $resolved);
    }

    public function testARepeatedIdCostsOneQueryNotOnePerCall(): void
    {
        $user = $this->user('cache-repeat@example.com');
        $news = $this->tag($user, 'News');
        $this->entityManager->flush();
        $newsId = $news->requireId();
        $userId = $user->requireId();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        for ($index = 0; $index < 5; ++$index) {
            $this->cache->findAllByIdsForUser($userId, [$newsId]);
        }

        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(
            1,
            $reads,
            "a repeated id must cost one query, not one per call, got:\n" . implode("\n", $reads),
        );
    }

    public function testOnlyTheMissingIdsAreFetchedOnASubsequentCall(): void
    {
        $user = $this->user('cache-partial@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->entityManager->flush();
        $userId = $user->requireId();

        $this->cache->findAllByIdsForUser($userId, [$news->requireId()]);

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $resolved = $this->cache->findAllByIdsForUser(
            $userId,
            [$news->requireId(), $tech->requireId()],
        );

        self::assertCount(2, $resolved);
        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(
            1,
            $reads,
            "the already-cached id must not be re-fetched, got:\n" . implode("\n", $reads),
        );
    }

    /** The functional tests reuse one container across requests, so reset() must really empty the cache. */
    public function testResetForgetsEverythingResolvedSoFar(): void
    {
        $user = $this->user('cache-reset@example.com');
        $news = $this->tag($user, 'News');
        $this->entityManager->flush();
        $newsId = $news->requireId();
        $userId = $user->requireId();

        $this->cache->findAllByIdsForUser($userId, [$newsId]);

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->cache->reset();
        $this->cache->findAllByIdsForUser($userId, [$newsId]);

        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(
            1,
            $reads,
            "reset() must drop the cached id so it is fetched again, got:\n" . implode("\n", $reads),
        );
    }

    /**
     * A dropped middle id must leave no key gap; assertSame() is key-sensitive, so a missing array_values() fails it.
     */
    public function testReturnsAPlainListWhenAMiddleIdDropsOut(): void
    {
        $user = $this->user('cache-gap@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->entityManager->flush();

        $resolved = $this->cache->findAllByIdsForUser(
            $user->requireId(),
            [$news->requireId(), 999_999, $tech->requireId()],
        );

        self::assertSame([$news, $tech], $resolved);
    }

    /**
     * A repeated id within one call is de-duplicated before the query: a query count cannot see that, but the SQL
     * does ("IN (?, ?)" instead of "IN (?)").
     */
    public function testARepeatedIdWithinOneCallIsDeduplicatedBeforeQuerying(): void
    {
        $user = $this->user('cache-dedup@example.com');
        $news = $this->tag($user, 'News');
        $this->entityManager->flush();
        $newsId = $news->requireId();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->cache->findAllByIdsForUser($user->requireId(), [$newsId, $newsId]);

        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(1, $reads, "a duplicated id must still cost one query, got:\n" . implode("\n", $reads));
        self::assertStringNotContainsString(
            'IN (?, ?)',
            $reads[0],
            "a duplicated id must not be bound twice in the IN (...) list, got:\n" . $reads[0],
        );
    }

    public function testKeepsSeparateUsersApart(): void
    {
        $mine = $this->user('cache-isolate-mine@example.com');
        $theirs = $this->user('cache-isolate-theirs@example.com');
        $sameId = $this->tag($mine, 'Mine');
        $this->entityManager->flush();

        $this->cache->findAllByIdsForUser($mine->requireId(), [$sameId->requireId()]);
        $resolvedForStranger = $this->cache->findAllByIdsForUser(
            $theirs->requireId(),
            [$sameId->requireId()],
        );

        self::assertSame([], $resolvedForStranger, "one user's cached tag must not leak into another's lookup.");
    }
}
