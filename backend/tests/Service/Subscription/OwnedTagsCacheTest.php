<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\OwnedTagsCache;
use App\Tests\Support\QueryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OwnedTagsCacheTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OwnedTagsCache $cache;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $cache = self::getContainer()->get(OwnedTagsCache::class);
        self::assertInstanceOf(OwnedTagsCache::class, $cache);
        $this->cache = $cache;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->em->persist($tag);

        return $tag;
    }

    public function testResolvesEveryOwnedId(): void
    {
        $user = $this->user('cache-owner@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->em->flush();

        $resolved = $this->cache->findAllByIdsForUser(
            [(int) $news->getId(), (int) $tech->getId()],
            (int) $user->getId(),
        );

        self::assertCount(2, $resolved);
    }

    public function testDropsAnIdBelongingToAnotherUser(): void
    {
        $mine = $this->user('cache-mine@example.com');
        $theirs = $this->user('cache-theirs@example.com');
        $foreignTag = $this->tag($theirs, 'Theirs');
        $this->em->flush();

        $resolved = $this->cache->findAllByIdsForUser([(int) $foreignTag->getId()], (int) $mine->getId());

        self::assertSame([], $resolved);
    }

    public function testDropsAnIdThatDoesNotExist(): void
    {
        $user = $this->user('cache-missing@example.com');
        $this->em->flush();

        $resolved = $this->cache->findAllByIdsForUser([999_999], (int) $user->getId());

        self::assertSame([], $resolved);
    }

    /**
     * The whole point of the cache: SubscriptionTagSync::sync() re-resolves
     * its requested ids on every call, and a bulk write calls sync() once per
     * subscription. Without this, a repeated id would cost one query per call.
     */
    public function testARepeatedIdCostsOneQueryNotOnePerCall(): void
    {
        $user = $this->user('cache-repeat@example.com');
        $news = $this->tag($user, 'News');
        $this->em->flush();
        $newsId = (int) $news->getId();
        $userId = (int) $user->getId();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        for ($i = 0; $i < 5; ++$i) {
            $this->cache->findAllByIdsForUser([$newsId], $userId);
        }

        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(
            1,
            $reads,
            "a repeated id must cost one query, not one per call, got:\n" . implode("\n", $reads),
        );
    }

    /**
     * A later call naming a NEW id on top of an already-cached one must only
     * fetch what is actually missing, not re-fetch the id it already knows.
     */
    public function testOnlyTheMissingIdsAreFetchedOnASubsequentCall(): void
    {
        $user = $this->user('cache-partial@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->em->flush();
        $userId = (int) $user->getId();

        $this->cache->findAllByIdsForUser([(int) $news->getId()], $userId);

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $resolved = $this->cache->findAllByIdsForUser(
            [(int) $news->getId(), (int) $tech->getId()],
            $userId,
        );

        self::assertCount(2, $resolved);
        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(
            1,
            $reads,
            "the already-cached id must not be re-fetched, got:\n" . implode("\n", $reads),
        );
    }

    /**
     * The class's own comment used to justify never clearing $resolvedByUser
     * on "one PHP process per request" — already false in this suite's own
     * functional tests that call $client->disableReboot() (#659 review).
     * reset() must actually empty the cache: a repeated id costs a fresh
     * query again once reset() has run.
     */
    public function testResetForgetsEverythingResolvedSoFar(): void
    {
        $user = $this->user('cache-reset@example.com');
        $news = $this->tag($user, 'News');
        $this->em->flush();
        $newsId = (int) $news->getId();
        $userId = (int) $user->getId();

        $this->cache->findAllByIdsForUser([$newsId], $userId);

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->cache->reset();
        $this->cache->findAllByIdsForUser([$newsId], $userId);

        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(
            1,
            $reads,
            "reset() must drop the cached id so it is fetched again, got:\n" . implode("\n", $reads),
        );
    }

    /**
     * findAllByIdsForUser() must return a plain list, in request order, even
     * when a requested id in the MIDDLE of the list drops out (foreign or
     * missing). array_filter() alone would leave a gap at that id's original
     * key — array_values() closes it. assertSame() is key-sensitive, so a
     * gapped result (e.g. keyed 0 => $news, 2 => $tech instead of 0, 1) fails
     * this assertion even though both "contain the right two tags".
     */
    public function testReturnsAPlainListWhenAMiddleIdDropsOut(): void
    {
        $user = $this->user('cache-gap@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->em->flush();

        $resolved = $this->cache->findAllByIdsForUser(
            [(int) $news->getId(), 999_999, (int) $tech->getId()],
            (int) $user->getId(),
        );

        self::assertSame([$news, $tech], $resolved);
    }

    /**
     * The cache's whole job is to turn a repeated id into a map lookup, not a
     * query — but that guarantee is not only about the NUMBER of queries
     * resolveMissing() issues. Asking for the SAME id twice in one call must
     * still de-duplicate before it ever reaches the repository: without
     * array_unique(), the one query resolveMissing() does run would carry the
     * id twice in its IN (...) list. That is invisible to a query COUNT, but
     * not to the query's own SQL text — a duplicated bound value shows up as
     * an extra placeholder, "IN (?, ?)" instead of "IN (?)".
     */
    public function testARepeatedIdWithinOneCallIsDeduplicatedBeforeQuerying(): void
    {
        $user = $this->user('cache-dedup@example.com');
        $news = $this->tag($user, 'News');
        $this->em->flush();
        $newsId = (int) $news->getId();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->cache->findAllByIdsForUser([$newsId, $newsId], (int) $user->getId());

        $reads = $recorder->queriesMatching('from tag');
        self::assertCount(1, $reads, "a duplicated id must still cost one query, got:\n" . implode("\n", $reads));
        self::assertStringNotContainsString(
            'IN (?, ?)',
            $reads[0],
            "a duplicated id must not be bound twice in the IN (...) list, got:\n" . $reads[0],
        );
    }

    /**
     * Two different users must never see each other's cached tags, even
     * though both ask this one instance within the same request.
     */
    public function testKeepsSeparateUsersApart(): void
    {
        $mine = $this->user('cache-isolate-mine@example.com');
        $theirs = $this->user('cache-isolate-theirs@example.com');
        $sameId = $this->tag($mine, 'Mine');
        $this->em->flush();

        $this->cache->findAllByIdsForUser([(int) $sameId->getId()], (int) $mine->getId());
        $resolvedForStranger = $this->cache->findAllByIdsForUser(
            [(int) $sameId->getId()],
            (int) $theirs->getId(),
        );

        self::assertSame([], $resolvedForStranger, "one user's cached tag must not leak into another's lookup.");
    }
}
