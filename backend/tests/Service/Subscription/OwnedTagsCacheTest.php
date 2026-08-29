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
