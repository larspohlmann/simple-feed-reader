<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership\MarkGroupWalk;

/**
 * What one membership sweep supplies to the shared mark-group walk: its members, its chunk matching and its writes.
 *
 * @template TMember
 * @template TMatches
 */
interface MarkGroupWalkInterface
{
    /** @param non-empty-list<TMember> $group the members whose mark the walk has reached */
    public function joinGroup(array $group): void;

    /**
     * @param non-empty-list<int> $chunk
     *
     * @return TMatches
     */
    public function matchChunk(array $chunk): mixed;

    /**
     * @param TMatches $matches
     *
     * @return int the rows added
     */
    public function insertMatches(mixed $matches): int;

    public function advanceMarks(int $entryId): void;

    public function logChunkFailure(\Throwable $exception): void;
}
