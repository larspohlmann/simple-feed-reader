<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\RestoreEntryShares;

interface RestoreEntrySharesInterface
{
    /**
     * Adds the shares the user does not hold yet; an existing share keeps its instant.
     *
     * @param array<int, \DateTimeImmutable> $sharedAtByEntryId
     */
    public function addMissingForUser(int $userId, array $sharedAtByEntryId): void;
}
