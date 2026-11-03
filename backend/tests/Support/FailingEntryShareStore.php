<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reading\EntryShareStore\EntryShareStoreInterface;

/** A share store whose every write fails the way a lost database connection or a duplicate key does. */
final class FailingEntryShareStore implements EntryShareStoreInterface
{
    public function record(int $userId, int $entryId, \DateTimeImmutable $when): void
    {
        throw DuplicateKeyViolation::exception();
    }

    public function remove(int $userId, int $entryId): void
    {
        throw DuplicateKeyViolation::exception();
    }

    public function sharedAtFor(int $userId, int $entryId): ?\DateTimeImmutable
    {
        return null;
    }
}
