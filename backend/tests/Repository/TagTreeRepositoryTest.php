<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagTreeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class TagTreeRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testLockParentIdsForUserMapsOnlyTheOwnersTagsToTheirParents(): void
    {
        $owner = $this->user('tag-lock-owner@example.com');
        $parent = $this->tag($owner);
        $child = $this->childOf($owner, $parent, 'Child');
        $this->tag($this->user('tag-lock-stranger@example.com'));

        $parentIds = $this->entityManager->getConnection()->transactional(
            fn (): array => $this->repository()->lockParentIdsForUser($owner->requireId()),
        );

        ksort($parentIds);
        self::assertSame([$parent->requireId() => null, $child->requireId() => $parent->requireId()], $parentIds);
    }

    public function testLockParentIdsForUserHoldsAWriteLockOnTheRows(): void
    {
        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has no row locks; it serialises writers itself.');
        }
        $owner = $this->user('tag-lock-rows@example.com');
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->entityManager->getConnection()->transactional(
            fn (): array => $this->repository()->lockParentIdsForUser($owner->requireId()),
        );

        self::assertCount(1, $recorder->queriesMatching('for update'));
    }

    private function repository(): TagTreeRepository
    {
        $repository = self::getContainer()->get(TagTreeRepository::class);
        self::assertInstanceOf(TagTreeRepository::class, $repository);

        return $repository;
    }

    private function tag(User $owner, string $name = 'News'): Tag
    {
        $tag = new Tag($owner, $name);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function childOf(User $owner, Tag $parent, string $name): Tag
    {
        $child = new Tag($owner, $name);
        $child->setParent($parent);
        $this->entityManager->persist($child);
        $this->entityManager->flush();

        return $child;
    }
}
