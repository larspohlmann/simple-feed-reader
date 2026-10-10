<?php

declare(strict_types=1);

namespace App\Repository;

/** The posts whose link cards were built once, as the list's pending flag reads them. */
interface TriedPostsInterface
{
    /**
     * @param list<int> $entryIds
     *
     * @return list<int> those among them that were tried
     */
    public function triedAmong(array $entryIds): array;
}
