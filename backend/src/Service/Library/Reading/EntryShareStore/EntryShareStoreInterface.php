<?php

declare(strict_types=1);

namespace App\Service\Library\Reading\EntryShareStore;

use Doctrine\DBAL\Exception as DbalException;

/** Where a reader's shares are kept: one per user and entry, its instant moved on by sharing again. */
interface EntryShareStoreInterface
{
    /**
     * Leaves an entry the user no longer subscribes to unshared.
     *
     * @throws DbalException
     */
    public function record(int $userId, int $entryId, \DateTimeImmutable $when): void;

    public function remove(int $userId, int $entryId): void;
}
