<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag;

use App\Service\Tag\TagStructureLock;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class TagStructureLockTest extends DbTestCase
{
    use SeedsUsers;

    public function testHoldReturnsTheWorksResultAndLeavesNoTransactionOpen(): void
    {
        $user = $this->user('tag-lock-commit@example.com');
        $level = $this->entityManager->getConnection()->getTransactionNestingLevel();

        self::assertSame('done', $this->lock()->hold($user, static fn (): string => 'done'));
        self::assertSame($level, $this->entityManager->getConnection()->getTransactionNestingLevel());
    }

    public function testHoldRollsBackAndRethrowsWhenTheWorkFails(): void
    {
        $user = $this->user('tag-lock-rollback@example.com');
        $level = $this->entityManager->getConnection()->getTransactionNestingLevel();

        $rethrown = null;
        try {
            $this->lock()->hold($user, static fn (): never => throw new \DomainException('refused'));
        } catch (\DomainException $exception) {
            $rethrown = $exception->getMessage();
        }

        self::assertSame('refused', $rethrown);
        self::assertSame($level, $this->entityManager->getConnection()->getTransactionNestingLevel());
    }

    private function lock(): TagStructureLock
    {
        $lock = self::getContainer()->get(TagStructureLock::class);
        self::assertInstanceOf(TagStructureLock::class, $lock);

        return $lock;
    }
}
