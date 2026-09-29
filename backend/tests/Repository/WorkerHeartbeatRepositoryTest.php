<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\WorkerHeartbeat;
use App\Tests\DbTestCase;
use App\Tests\Support\ProvidesWorkerHeartbeats;

final class WorkerHeartbeatRepositoryTest extends DbTestCase
{
    use ProvidesWorkerHeartbeats;

    private const string AT = '2026-09-25 10:00:00';
    private const string LATER = '2026-09-25 10:00:30';

    public function testTouchInsertsARowForANewName(): void
    {
        $this->heartbeats()->touch('new', new \DateTimeImmutable(self::AT));

        self::assertSame(['new'], $this->storedNames());
        self::assertSame(self::AT, $this->storedTouchedAt('new'));
    }

    public function testTouchMovesAnExistingRowToTheNewInstant(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));

        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::LATER));

        self::assertSame(['x'], $this->storedNames());
        self::assertSame(self::LATER, $this->storedTouchedAt('x'));
    }

    public function testForgettingANameThatWasNeverTouchedChangesNothing(): void
    {
        $this->heartbeats()->touch('kept', new \DateTimeImmutable(self::AT));

        $this->heartbeats()->forget('never-touched');

        self::assertSame(['kept'], $this->storedNames());
    }

    public function testTouchLeavesSomeoneElsesPendingChangesUnflushed(): void
    {
        $this->entityManager->persist(new WorkerHeartbeat('pending', new \DateTimeImmutable(self::AT)));

        $this->heartbeats()->touch('touched', new \DateTimeImmutable(self::AT));

        self::assertSame(['touched'], $this->storedNames());
    }

    public function testForgetLeavesSomeoneElsesPendingChangesUnflushed(): void
    {
        $this->heartbeats()->touch('forgotten', new \DateTimeImmutable(self::AT));
        $this->entityManager->persist(new WorkerHeartbeat('pending', new \DateTimeImmutable(self::AT)));

        $this->heartbeats()->forget('forgotten');

        self::assertSame([], $this->storedNames());
    }

    public function testTouchingTwiceWithTheSameInstantKeepsOneRow(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));

        self::assertSame(['x'], $this->storedNames());
        self::assertEquals(new \DateTimeImmutable(self::AT), $this->heartbeats()->findTouchedAt('x'));
    }

    public function testAReadAfterATouchSeesTheNewInstantEvenAfterAnEarlierRead(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));
        $this->heartbeats()->findTouchedAtByNames(['x']);

        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::LATER));

        self::assertEquals(new \DateTimeImmutable(self::LATER), $this->heartbeats()->findTouchedAt('x'));
    }

    /** @return list<string> */
    private function storedNames(): array
    {
        /** @var list<string> $names */
        $names = $this->entityManager
            ->getConnection()
            ->fetchFirstColumn('SELECT name FROM worker_heartbeat ORDER BY name');

        return $names;
    }

    private function storedTouchedAt(string $name): string
    {
        $touchedAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT touched_at FROM worker_heartbeat WHERE name = :name',
            ['name' => $name],
        );
        self::assertIsString($touchedAt);

        return $touchedAt;
    }
}
