<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProfileRun;
use App\Entity\StoryAiRun;
use App\Entity\User;
use App\Enum\AiRunKind;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\StoryAiTask;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Service\Curation\Recommendation\Feed\Model\MonthWindowModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryCursorModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryFilterModel;
use App\Service\Foundation\Clock\Model\ViewerTimeZoneModel;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class RecommendationRunHistoryRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testReturnsOnlyTheRunsInsideTheMonthWindow(): void
    {
        $user = $this->user('half-open-window@example.com');

        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-07-31 23:00:00'));
        $augustStart = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-01 00:00:00'));
        $augustEnd = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-31 23:59:00'));
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-09-01 00:00:00'));

        $window = MonthWindowModel::fromMonth('2026-08', ViewerTimeZoneModel::fromIdentifier(null));
        $rows = $this->historyRepository()->pageForMonth($user, $window, RunHistoryFilterModel::all());

        // Half-open at both ends: the July run at 23:00 and the September run
        // at 00:00:00 both miss the window, leaving exactly the two August
        // runs, newest first.
        self::assertSame([$augustEnd->getId(), $augustStart->getId()], array_column($rows, 'id'));
    }

    public function testABerlinViewersAugustExcludesTheRunThatPrintsAsSeptember(): void
    {
        $user = $this->user('berlin-boundary@example.com');
        // A control run inside the month: without it, a window that excluded the whole month would still pass the
        // boundary assertions.
        $control = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-15 12:00:00'));
        $boundary = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-31 23:30:00'));

        $repository = $this->historyRepository();

        $august = MonthWindowModel::fromMonth('2026-08', ViewerTimeZoneModel::fromIdentifier('Europe/Berlin'));
        self::assertSame(
            [$control->getId()],
            array_column($repository->pageForMonth($user, $august, RunHistoryFilterModel::all()), 'id'),
        );

        $september = MonthWindowModel::fromMonth('2026-09', ViewerTimeZoneModel::fromIdentifier('Europe/Berlin'));
        self::assertSame(
            [$boundary->getId()],
            array_column($repository->pageForMonth($user, $september, RunHistoryFilterModel::all()), 'id'),
        );
    }

    public function testReadsOneRowMoreThanTheLimitSoTheCallerCanTellThereIsAnother(): void
    {
        $user = $this->user('over-read@example.com');

        $runCount = RecommendationRunHistoryRepository::HISTORY_LIMIT + 3;
        $fixtures = $this->fixtures();
        for ($minute = 0; $minute < $runCount; $minute++) {
            $fixtures->persistRunAt($user, new \DateTimeImmutable(sprintf('2026-08-01 00:%02d:00', $minute)));
        }

        $window = MonthWindowModel::fromMonth('2026-08', ViewerTimeZoneModel::fromIdentifier(null));
        $rows = $this->historyRepository()->pageForMonth($user, $window, RunHistoryFilterModel::all());

        self::assertCount(RecommendationRunHistoryRepository::HISTORY_LIMIT + 1, $rows);
    }

    public function testPagesBackwardsFromTheCursor(): void
    {
        $user = $this->user('cursor@example.com');

        $runs = [];
        for ($minute = 0; $minute < 5; $minute++) {
            $runs[] = $this->fixtures()->persistRunAt(
                $user,
                new \DateTimeImmutable(sprintf('2026-08-01 00:%02d:00', $minute)),
            );
        }

        $window = MonthWindowModel::fromMonth('2026-08', ViewerTimeZoneModel::fromIdentifier(null));
        $before = new RunHistoryCursorModel($runs[2]->getCreatedAt(), AiRunKind::ForYou, $runs[2]->requireId());
        $rows = $this->historyRepository()->pageForMonth($user, $window, new RunHistoryFilterModel(null, $before));

        self::assertSame([$runs[1]->getId(), $runs[0]->getId()], array_column($rows, 'id'));
    }

    public function testNeverReturnsAnotherAccountsRuns(): void
    {
        $user = $this->user('page-scope-mine@example.com');
        $otherUser = $this->user('page-scope-theirs@example.com');

        $mine = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-05 00:00:00'));
        $this->fixtures()->persistRunAt($otherUser, new \DateTimeImmutable('2026-08-05 00:00:00'));

        $window = MonthWindowModel::fromMonth('2026-08', ViewerTimeZoneModel::fromIdentifier(null));
        $rows = $this->historyRepository()->pageForMonth($user, $window, RunHistoryFilterModel::all());

        self::assertSame([$mine->getId()], array_column($rows, 'id'));
    }

    public function testTheSpendTimelineCarriesEveryRunOfTheAccountAndNoOther(): void
    {
        $user = $this->user('spend-timeline-mine@example.com');
        $otherUser = $this->user('spend-timeline-theirs@example.com');

        $older = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-01 00:00:00'));
        $newer = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-02 00:00:00'));
        $this->fixtures()->persistRunAt($otherUser, new \DateTimeImmutable('2026-08-01 12:00:00'));

        $this->fixtures()->priceRun($older, 1_000);
        // $newer is left unpriced deliberately, to prove null survives to the
        // caller rather than being coerced into 0 or dropped.

        $rows = $this->historyRepository()->spendTimeline($user, null);

        self::assertCount(2, $rows);
        self::assertEquals($newer->getCreatedAt(), $rows[0]['createdAt']);
        self::assertNull($rows[0]['costNanoCredits']);
        self::assertEquals($older->getCreatedAt(), $rows[1]['createdAt']);
        self::assertSame(1_000, (int) $rows[1]['costNanoCredits']);
    }

    public function testPageMergesEveryKindNewestFirst(): void
    {
        $user = $this->user('history-merge@example.com');
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-03 10:00:00'));
        $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-03 10:01:00'));
        $this->persistStoryRunAt($user, new \DateTimeImmutable('2026-08-03 10:02:00'));

        $rows = $this->historyRepository()->pageForMonth($user, $this->august(), RunHistoryFilterModel::all());

        self::assertSame([AiRunKind::Story, AiRunKind::Profile, AiRunKind::ForYou], array_column($rows, 'kind'));
    }

    public function testKindFilterKeepsOneKind(): void
    {
        $user = $this->user('history-kind-filter@example.com');
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-03 10:00:00'));
        $profileRun = $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-03 10:01:00'));
        $this->persistStoryRunAt($user, new \DateTimeImmutable('2026-08-03 10:02:00'));

        $rows = $this->historyRepository()->pageForMonth(
            $user,
            $this->august(),
            new RunHistoryFilterModel(AiRunKind::Profile, null),
        );

        self::assertSame([AiRunKind::Profile], array_column($rows, 'kind'));
        self::assertSame([$profileRun->getId()], array_column($rows, 'id'));
    }

    public function testStoryRowHasNoStatusOrCompletion(): void
    {
        $user = $this->user('history-story-row@example.com');
        $storyRun = $this->persistStoryRunAt($user, new \DateTimeImmutable('2026-08-03 10:02:00'));
        $this->priceRow('story_ai_run', $storyRun->requireId(), 7_500);

        $rows = $this->historyRepository()->pageForMonth($user, $this->august(), RunHistoryFilterModel::all());

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['status']);
        self::assertNull($rows[0]['completedAt']);
        self::assertSame(7_500, (int) $rows[0]['costNanoCredits']);
    }

    public function testPageBreakInsideOneSecondKeepsEveryRun(): void
    {
        $user = $this->user('history-same-second@example.com');
        $createdAt = new \DateTimeImmutable('2026-08-03 10:00:00');
        $firstStoryRun = $this->persistStoryRunAt($user, $createdAt);
        for ($index = 1; $index < RecommendationRunHistoryRepository::HISTORY_LIMIT + 1; $index++) {
            $this->persistStoryRunAt($user, $createdAt);
        }
        $this->fixtures()->persistRunAt($user, $createdAt);
        $repository = $this->historyRepository();

        $firstPage = $repository->pageForMonth($user, $this->august(), RunHistoryFilterModel::all());

        self::assertCount(RecommendationRunHistoryRepository::HISTORY_LIMIT + 1, $firstPage);
        self::assertSame(AiRunKind::ForYou, $firstPage[0]['kind']);

        $lastKept = $firstPage[RecommendationRunHistoryRepository::HISTORY_LIMIT - 1];
        $before = new RunHistoryCursorModel($lastKept['createdAt'], $lastKept['kind'], $lastKept['id']);
        $secondPage = $repository->pageForMonth($user, $this->august(), new RunHistoryFilterModel(null, $before));

        self::assertSame(
            [$firstPage[RecommendationRunHistoryRepository::HISTORY_LIMIT]['id'], $firstStoryRun->requireId()],
            array_column($secondPage, 'id'),
        );
        self::assertSame([AiRunKind::Story, AiRunKind::Story], array_column($secondPage, 'kind'));
    }

    public function testACursorOnAForYouRunStillReachesAStoryRunOfTheSameSecond(): void
    {
        $user = $this->user('history-cursor-lower-rank@example.com');
        $createdAt = new \DateTimeImmutable('2026-08-03 10:00:00');
        $forYouRun = $this->fixtures()->persistRunAt($user, $createdAt);
        $storyRun = $this->persistStoryRunAt($user, $createdAt);
        $before = new RunHistoryCursorModel($createdAt, AiRunKind::ForYou, $forYouRun->requireId());

        $rows = $this->historyRepository()->pageForMonth(
            $user,
            $this->august(),
            new RunHistoryFilterModel(null, $before),
        );

        self::assertSame([$storyRun->requireId()], array_column($rows, 'id'));
    }

    public function testTotalSumsEveryKind(): void
    {
        $user = $this->user('history-total@example.com');
        $forYouRun = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-03 10:00:00'));
        $profileRun = $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-03 10:01:00'));
        $storyRun = $this->persistStoryRunAt($user, new \DateTimeImmutable('2026-08-03 10:02:00'));
        $this->priceRow('recommendation_run', $forYouRun->requireId(), 1_000);
        $this->priceRow('profile_run', $profileRun->requireId(), 200);
        $this->priceRow('story_ai_run', $storyRun->requireId(), 30);

        self::assertSame(1_230, $this->historyRepository()->totalCostNanoCredits($user));
    }

    public function testTotalIsNullWhenNoKindWasPriced(): void
    {
        $user = $this->user('history-total-unpriced@example.com');
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-03 10:00:00'));
        $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-03 10:01:00'));
        $this->persistStoryRunAt($user, new \DateTimeImmutable('2026-08-03 10:02:00'));

        self::assertNull($this->historyRepository()->totalCostNanoCredits($user));
    }

    public function testSpendTimelineFiltersByKind(): void
    {
        $user = $this->user('history-timeline-kind@example.com');
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-03 10:00:00'));
        $profileRun = $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-03 10:01:00'));
        $storyRun = $this->persistStoryRunAt($user, new \DateTimeImmutable('2026-08-03 10:02:00'));
        $repository = $this->historyRepository();

        $profileTimeline = $repository->spendTimeline($user, AiRunKind::Profile);
        $everyKind = $repository->spendTimeline($user, null);

        self::assertCount(1, $profileTimeline);
        self::assertEquals($profileRun->getCreatedAt(), $profileTimeline[0]['createdAt']);
        self::assertCount(3, $everyKind);
        self::assertEquals($storyRun->getCreatedAt(), $everyKind[0]['createdAt']);
    }

    public function testProfileRunsThatFinishedWithoutACallAreLeftOutOfPageTimelineAndTotal(): void
    {
        $user = $this->user('history-profile-no-call@example.com');
        $generated = $this->persistProfileRunFinishedAt(
            $user,
            new \DateTimeImmutable('2026-08-03 10:00:00'),
            ProfileRunOutcome::Generated,
        );
        $unchanged = $this->persistProfileRunFinishedAt(
            $user,
            new \DateTimeImmutable('2026-08-03 10:01:00'),
            ProfileRunOutcome::Unchanged,
        );
        $noHistory = $this->persistProfileRunFinishedAt(
            $user,
            new \DateTimeImmutable('2026-08-03 10:02:00'),
            ProfileRunOutcome::NoHistory,
        );
        $inFlight = $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-03 10:03:00'));
        $this->priceRow('profile_run', $generated->requireId(), 200);
        $this->priceRow('profile_run', $unchanged->requireId(), 40);
        $this->priceRow('profile_run', $noHistory->requireId(), 5);
        $repository = $this->historyRepository();

        $page = $repository->pageForMonth($user, $this->august(), RunHistoryFilterModel::all());
        $timeline = $repository->spendTimeline($user, AiRunKind::Profile);

        self::assertSame([$inFlight->requireId(), $generated->requireId()], array_column($page, 'id'));
        self::assertEquals(
            [$inFlight->getCreatedAt(), $generated->getCreatedAt()],
            array_column($timeline, 'createdAt'),
        );
        self::assertSame(200, $repository->totalCostNanoCredits($user));
    }

    private function august(): MonthWindowModel
    {
        return MonthWindowModel::fromMonth('2026-08', ViewerTimeZoneModel::fromIdentifier(null));
    }

    private function persistProfileRunAt(User $user, \DateTimeImmutable $createdAt): ProfileRun
    {
        $run = new ProfileRun($user, ProfileRunTrigger::Manual, $createdAt);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function persistProfileRunFinishedAt(
        User $user,
        \DateTimeImmutable $createdAt,
        ProfileRunOutcome $outcome,
    ): ProfileRun {
        $run = $this->persistProfileRunAt($user, $createdAt);
        $run->start('fingerprint', 'llm.example.test', 'qwen3-14b');
        $run->complete($outcome, $createdAt->modify('+1 minute'));
        $this->entityManager->flush();

        return $run;
    }

    private function persistStoryRunAt(User $user, \DateTimeImmutable $createdAt): StoryAiRun
    {
        $run = new StoryAiRun($user, StoryAiTask::Title, $createdAt);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /** Raw SQL, as RecordedCall banks a price in production; no run entity has a cost setter. */
    private function priceRow(string $table, int $id, int $costNanoCredits): void
    {
        $this->entityManager->getConnection()->executeStatement(
            sprintf('UPDATE %s SET cost_nano_credits = :cost WHERE id = :id', $table),
            ['cost' => $costNanoCredits, 'id' => $id],
        );
    }

    private function historyRepository(): RecommendationRunHistoryRepository
    {
        /** @var RecommendationRunHistoryRepository $repository */
        $repository = self::getContainer()->get(RecommendationRunHistoryRepository::class);

        return $repository;
    }

    private function fixtures(): RecommendationRunFixtures
    {
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        return new RecommendationRunFixtures($this->entityManager, $cipher);
    }
}
