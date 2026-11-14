<?php

declare(strict_types=1);

namespace App\Service\Backup\RestoreEntryStates;

interface RestoreEntryStatesInterface
{
    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function entryIdsWithStateForUser(int $userId, array $entryIds): array;
}
