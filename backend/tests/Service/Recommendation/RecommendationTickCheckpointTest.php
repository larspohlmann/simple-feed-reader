<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\RecommendationTickCheckpoint;
use App\Tests\DbTestCase;

/**
 * wasStopped()'s "$run->getId() ?? 0" fallback is dead in production --
 * RecommendationRunAdvancer only ever checkpoints a run it has already
 * persisted and started ticking, so getId() is never actually null there.
 * It stays because RecommendationRun::getId(): ?int is genuinely nullable
 * for a transient (unpersisted) run, and guard() is a public method on its
 * own service, callable with one directly.
 *
 * The fallback literal still has to be the right one: a query scoped to the
 * wrong id would silently read a *different*, real run's status instead of
 * reliably finding none for a run that was never saved. These tests move a
 * real, cancelled run to id 0 -- the literal the fallback actually uses --
 * so a mutation of that literal (to 1 or -1) is provably distinguishable:
 * the mutated query would no longer find the row this test put there.
 */
final class RecommendationTickCheckpointTest extends DbTestCase
{
    public function testATransientRunStopsWhenTheRunAtTheFallbackIdIsCancelled(): void
    {
        $this->moveACancelledRunToId(0);

        $this->expectException(RecommendationRunCancelledException::class);

        $this->checkpoint()->guard($this->transientRun());
    }

    public function testATransientRunDoesNotStopWhenNothingSitsAtTheFallbackId(): void
    {
        // A cancelled run parked well away from the fallback id: present in
        // the table, but not where wasStopped() looks, so this pins that the
        // guard reads its own id, not "any cancelled run anywhere".
        $this->moveACancelledRunToId(4200);

        $this->checkpoint()->guard($this->transientRun());

        $this->addToAssertionCount(1); // guard() returned instead of throwing
    }

    /**
     * Persists a real, cancelled run through the ORM as usual, then moves it
     * to the given id with a raw UPDATE -- Doctrine's id generator only ever
     * hands out positive, ascending values, so reaching id 0 (or any chosen
     * id) at all needs to sidestep it once the row already exists.
     */
    private function moveACancelledRunToId(int $id): void
    {
        $user = new User('checkpoint-fallback@example.test', new \DateTimeImmutable('2026-08-16T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->cancel(new \DateTimeImmutable('2026-08-16T09:05:00Z'));
        $this->em->persist($run);
        $this->em->flush();

        $realId = $run->getId();
        self::assertNotNull($realId, 'The run must be persisted before its id can be moved.');

        $this->em->getConnection()->executeStatement(
            'UPDATE recommendation_run SET id = ? WHERE id = ?',
            [$id, $realId],
        );
        // The entity manager's identity map still holds $run under its old
        // id; clear() forces the next query to read the row back fresh.
        $this->em->clear();
    }

    private function transientRun(): RecommendationRun
    {
        $user = new User('checkpoint-transient@example.test', new \DateTimeImmutable('2026-08-16T00:00:00Z'));

        return new RecommendationRun($user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));
    }

    private function checkpoint(): RecommendationTickCheckpoint
    {
        /** @var RecommendationTickCheckpoint $checkpoint */
        $checkpoint = self::getContainer()->get(RecommendationTickCheckpoint::class);

        return $checkpoint;
    }
}
