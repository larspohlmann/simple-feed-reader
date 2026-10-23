<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\TagRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TagRepositoryTest extends DbTestCase
{
    public function testGetOneForUserReturnsTheOwnersTag(): void
    {
        $owner = $this->userFactory()->create('tag-owner@example.com');
        $tag = $this->tag($owner);

        self::assertSame($tag, $this->repository()->getOneForUser($owner->requireId(), $tag->requireId()));
    }

    public function testGetOneForUserRefusesAnotherUsersTag(): void
    {
        $tag = $this->tag($this->userFactory()->create('tag-owner@example.com'));
        $stranger = $this->userFactory()->create('tag-stranger@example.com');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such tag.');

        $this->repository()->getOneForUser($stranger->requireId(), $tag->requireId());
    }

    public function testFindChildrenListsATagsChildrenInPositionOrder(): void
    {
        $owner = $this->userFactory()->create('tag-parent@example.com');
        $parent = $this->tag($owner);
        $second = $this->childOf($owner, $parent, 'Second', 2);
        $first = $this->childOf($owner, $parent, 'First', 1);
        $this->tag($owner, 'Unrelated');

        self::assertSame([$first, $second], $this->repository()->findChildren($parent));
        self::assertTrue($this->repository()->hasChildren($parent));
        self::assertFalse($this->repository()->hasChildren($first));
    }

    private function repository(): TagRepository
    {
        $repository = $this->entityManager->getRepository(Tag::class);
        self::assertInstanceOf(TagRepository::class, $repository);

        return $repository;
    }

    private function userFactory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->entityManager, $hasher);
    }

    private function tag(User $owner, string $name = 'News'): Tag
    {
        $tag = new Tag($owner, $name);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function childOf(User $owner, Tag $parent, string $name, int $position): Tag
    {
        $child = new Tag($owner, $name);
        $child->setParent($parent);
        $child->setPosition($position);
        $this->entityManager->persist($child);
        $this->entityManager->flush();

        return $child;
    }
}
