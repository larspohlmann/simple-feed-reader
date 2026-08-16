<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\MonthWindow;
use App\Service\Recommendation\ViewerTimeZone;
use App\Tests\DbTestCase;

final class RecommendationRunHistoryRepositoryTest extends DbTestCase
{
    public function testReturnsOnlyTheRunsInsideTheMonthWindow(): void
    {
        $user = $this->persistUser('half-open-window@example.com');

        $this->persistRunAt($user, new \DateTimeImmutable('2026-07-31 23:00:00'));
        $augustStart = $this->persistRunAt($user, new \DateTimeImmutable('2026-08-01 00:00:00'));
        $augustEnd = $this->persistRunAt($user, new \DateTimeImmutable('2026-08-31 23:59:00'));
        $this->persistRunAt($user, new \DateTimeImmutable('2026-09-01 00:00:00'));

        $window = MonthWindow::of('2026-08', ViewerTimeZone::of(null));
        $rows = $this->historyRepository()->pageForMonth($user, $window, null);

        // Half-open at both ends: the July run at 23:00 and the September run
        // at 00:00:00 both miss the window, leaving exactly the two August
        // runs, newest first.
        self::assertSame([$augustEnd->getId(), $augustStart->getId()], array_column($rows, 'id'));
    }

    public function testABerlinViewersAugustExcludesTheRunThatPrintsAsSeptember(): void
    {
        $user = $this->persistUser('berlin-boundary@example.com');
        // A control run safely inside the month, so the assertions below prove
        // the window is correctly PLACED (this one is kept) and not merely
        // correctly ordered (the boundary run alone would also pass an empty
        // result if the window excluded the whole month by mistake).
        $control = $this->persistRunAt($user, new \DateTimeImmutable('2026-08-15 12:00:00'));
        $boundary = $this->persistRunAt($user, new \DateTimeImmutable('2026-08-31 23:30:00'));

        $repository = $this->historyRepository();

        $august = MonthWindow::of('2026-08', ViewerTimeZone::of('Europe/Berlin'));
        self::assertSame(
            [$control->getId()],
            array_column($repository->pageForMonth($user, $august, null), 'id'),
        );

        $september = MonthWindow::of('2026-09', ViewerTimeZone::of('Europe/Berlin'));
        self::assertSame(
            [$boundary->getId()],
            array_column($repository->pageForMonth($user, $september, null), 'id'),
        );
    }

    public function testReadsOneRowMoreThanTheLimitSoTheCallerCanTellThereIsAnother(): void
    {
        $user = $this->persistUser('over-read@example.com');

        $runCount = RecommendationRunHistoryRepository::HISTORY_LIMIT + 3;
        for ($minute = 0; $minute < $runCount; $minute++) {
            $this->persistRunAt($user, new \DateTimeImmutable(sprintf('2026-08-01 00:%02d:00', $minute)));
        }

        $window = MonthWindow::of('2026-08', ViewerTimeZone::of(null));
        $rows = $this->historyRepository()->pageForMonth($user, $window, null);

        self::assertCount(RecommendationRunHistoryRepository::HISTORY_LIMIT + 1, $rows);
    }

    public function testPagesBackwardsFromTheCursor(): void
    {
        $user = $this->persistUser('cursor@example.com');

        $ids = [];
        for ($minute = 0; $minute < 5; $minute++) {
            $ids[] = $this->persistRunAt(
                $user,
                new \DateTimeImmutable(sprintf('2026-08-01 00:%02d:00', $minute)),
            )->getId();
        }

        $window = MonthWindow::of('2026-08', ViewerTimeZone::of(null));
        $rows = $this->historyRepository()->pageForMonth($user, $window, $ids[2]);

        self::assertSame([$ids[1], $ids[0]], array_column($rows, 'id'));
    }

    public function testNeverReturnsAnotherAccountsRuns(): void
    {
        $user = $this->persistUser('page-scope-mine@example.com');
        $otherUser = $this->persistUser('page-scope-theirs@example.com');

        $mine = $this->persistRunAt($user, new \DateTimeImmutable('2026-08-05 00:00:00'));
        $this->persistRunAt($otherUser, new \DateTimeImmutable('2026-08-05 00:00:00'));

        $window = MonthWindow::of('2026-08', ViewerTimeZone::of(null));
        $rows = $this->historyRepository()->pageForMonth($user, $window, null);

        self::assertSame([$mine->getId()], array_column($rows, 'id'));
    }

    public function testTheSpendTimelineCarriesEveryRunOfTheAccountAndNoOther(): void
    {
        $user = $this->persistUser('spend-timeline-mine@example.com');
        $otherUser = $this->persistUser('spend-timeline-theirs@example.com');

        $older = $this->persistRunAt($user, new \DateTimeImmutable('2026-08-01 00:00:00'));
        $newer = $this->persistRunAt($user, new \DateTimeImmutable('2026-08-02 00:00:00'));
        $this->persistRunAt($otherUser, new \DateTimeImmutable('2026-08-01 12:00:00'));

        $this->priceRun($older, 1_000);
        // $newer is left unpriced deliberately, to prove null survives to the
        // caller rather than being coerced into 0 or dropped.

        $rows = $this->historyRepository()->spendTimeline($user);

        self::assertCount(2, $rows);
        self::assertEquals($newer->getCreatedAt(), $rows[0]['createdAt']);
        self::assertNull($rows[0]['costNanoCredits']);
        self::assertEquals($older->getCreatedAt(), $rows[1]['createdAt']);
        self::assertSame(1_000, (int) $rows[1]['costNanoCredits']);
    }

    private function historyRepository(): RecommendationRunHistoryRepository
    {
        /** @var RecommendationRunHistoryRepository $repository */
        $repository = self::getContainer()->get(RecommendationRunHistoryRepository::class);

        return $repository;
    }

    private function persistUser(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistRunAt(User $user, \DateTimeImmutable $createdAt): RecommendationRun
    {
        $run = new RecommendationRun($user, $createdAt);
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /**
     * The provider price is banked through raw SQL arithmetic in production
     * (RecordedCall::bankUsage(), never through the entity — see
     * ProviderUsage's class doc), so a fixture that wants a priced run has to
     * write the same column the same way rather than call a setter that does
     * not exist.
     */
    private function priceRun(RecommendationRun $run, int $costNanoCredits): void
    {
        $id = $run->getId();
        self::assertNotNull($id);

        $this->em->getConnection()->executeStatement(
            'UPDATE recommendation_run SET cost_nano_credits = :cost WHERE id = :id',
            ['cost' => $costNanoCredits, 'id' => $id],
        );
        $this->em->clear();
    }
}
