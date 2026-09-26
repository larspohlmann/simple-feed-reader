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
    public function testGetOneOwnedByReturnsTheOwnersTag(): void
    {
        $owner = $this->userFactory()->create('tag-owner@example.com');
        $tag = $this->tag($owner);

        self::assertSame($tag, $this->repo()->getOneOwnedBy($tag->requireId(), $owner->requireId()));
    }

    public function testGetOneOwnedByRefusesAnotherUsersTag(): void
    {
        $tag = $this->tag($this->userFactory()->create('tag-owner@example.com'));
        $stranger = $this->userFactory()->create('tag-stranger@example.com');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such tag.');

        $this->repo()->getOneOwnedBy($tag->requireId(), $stranger->requireId());
    }

    private function repo(): TagRepository
    {
        $repo = $this->em->getRepository(Tag::class);
        self::assertInstanceOf(TagRepository::class, $repo);

        return $repo;
    }

    private function userFactory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->em, $hasher);
    }

    private function tag(User $owner): Tag
    {
        $tag = new Tag($owner, 'News');
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }
}
