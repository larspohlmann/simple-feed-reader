<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\UnpersistedEntityException;
use App\Entity\User;
use App\Tests\DbTestCase;

final class PersistedIdTest extends DbTestCase
{
    public function testAFlushedEntityHandsBackItsId(): void
    {
        $user = $this->flushedUser();

        self::assertSame($user->getId(), $user->requireId());
    }

    public function testAnUninitializedReferenceAnswersWithoutLoading(): void
    {
        $id = $this->flushedUser()->requireId();
        $this->em->clear();

        $reference = $this->em->getReference(User::class, $id);
        self::assertNotNull($reference);

        self::assertSame($id, $reference->requireId());
        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($reference));
    }

    public function testAnUnsavedEntityIsRefused(): void
    {
        $this->expectException(UnpersistedEntityException::class);
        $this->expectExceptionMessage(User::class);

        (new User('unsaved@example.com', new \DateTimeImmutable('2026-09-25T00:00:00Z')))->requireId();
    }

    private function flushedUser(): User
    {
        $user = new User('persisted@example.com', new \DateTimeImmutable('2026-09-25T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
