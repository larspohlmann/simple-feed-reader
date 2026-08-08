<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\CompletionStreamProgress;
use App\Service\Recommendation\RecommendationCallRecorder;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationCallRecorderTest extends DbTestCase
{
    private User $user;
    private RecommendationRun $run;
    private MockClock $clock;
    private RecommendationCallRecorder $recorder;
    private RecommendationRunLogRepository $logs;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $this->user = $factory->create('recorder-owner@example.test');

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);

        $this->run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->em->persist($this->run);
        $this->em->flush();

        $this->clock = new MockClock('2026-08-08T10:00:00Z');

        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        $this->logs = $logs;

        /** @var RecommendationSettingsResolver $settingsResolver */
        $settingsResolver = self::getContainer()->get(RecommendationSettingsResolver::class);

        $this->recorder = new RecommendationCallRecorder(
            $this->em,
            $this->logs,
            $this->em->getConnection(),
            $settingsResolver,
            $this->clock,
        );
    }

    public function testBeginWithDebugOnPersistsTheRequestBodyImmediately(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);

        $this->recorder->begin(
            $this->run,
            RecommendationRunLog::PHASE_BATCH,
            2,
            [['role' => 'user', 'content' => 'hi']],
            'm',
        );

        $rows = $this->logs()->listForUser($this->user);
        self::assertCount(1, $rows);
        self::assertSame('batch', $rows[0]['phase']);
        self::assertSame(2, $rows[0]['batchNumber']);
        self::assertSame(1, $rows[0]['attempt']);
        self::assertNull($rows[0]['verdict']);
        $log = $this->freshLog($rows[0]['id']);
        self::assertStringContainsString('"model": "m"', $log->getRequestBody());
        self::assertStringContainsString('"content": "hi"', $log->getRequestBody());
        self::assertEquals($this->clock->now(), $log->getCreatedAt());
    }

    public function testBeginWithDebugOffWritesNoRow(): void
    {
        $this->fixtures->debugDisabledSettings($this->user);

        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        self::assertSame([], $this->logs()->listForUser($this->user));
    }

    public function testCheckpointsAreThrottledToTheInterval(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];

        $call->streamProgressed(new CompletionStreamProgress('He', 40));
        self::assertSame(
            '',
            $this->freshLog($logId)->getResponseText(),
            'first growth inside the interval is not written',
        );

        $this->clock->modify('+3 seconds');
        $call->streamProgressed(new CompletionStreamProgress('Hello', 90));

        self::assertSame('Hello', $this->freshLog($logId)->getResponseText());
    }

    public function testCheckpointUpdatesTheLivenessCounterEvenWithDebugOff(): void
    {
        $this->fixtures->debugDisabledSettings($this->user);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        $this->clock->modify('+3 seconds');
        $call->streamProgressed(new CompletionStreamProgress('He', 1_234));

        $runId = $this->run->getId();
        self::assertNotNull($runId);
        $this->em->clear();
        $freshRun = $this->em->find(RecommendationRun::class, $runId);
        self::assertSame(1_234, $freshRun?->getStreamedChars());
    }

    public function testFinishUsableStoresTextVerdictAndResetsLiveness(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->streamProgressed(new CompletionStreamProgress('partial', 7_000));

        $call->finishUsable('{"recommendations": []}');

        $log = $this->freshLog($logId);
        self::assertSame('{"recommendations": []}', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_USABLE, $log->getVerdict());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
        $freshRun = $this->em->find(RecommendationRun::class, $this->run->getId());
        self::assertSame(0, $freshRun?->getStreamedChars());
    }

    public function testAbortKeepsThePartialTextWithTransportVerdictAndTheTransportMessage(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->streamProgressed(new CompletionStreamProgress('cut off', 9_001));

        $call->abortAfterTransportFailure('cURL error 28');

        $log = $this->freshLog($logId);
        self::assertSame('cut off', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_TRANSPORT_FAILED, $log->getVerdict());
        self::assertSame(9_001, $log->getWireBytes());
        self::assertSame('cURL error 28', $log->getErrorDetail());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
    }

    /**
     * The #320 case the panel exists to explain: a reasoning model streams
     * megabytes and never answers, so the call dies with an empty response.
     * Without the byte count that row is indistinguishable from a provider
     * that said nothing at all.
     */
    public function testAnAbortRecordsTheBytesEvenWhenNothingWasAnswered(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];

        // Inside the checkpoint interval on purpose: no write has happened,
        // so the count can only reach the row if every report tracks it.
        $call->streamProgressed(new CompletionStreamProgress('', 1_900_000));
        $call->abortAfterTransportFailure(null);

        $log = $this->freshLog($logId);
        self::assertSame('', $log->getResponseText());
        self::assertSame(1_900_000, $log->getWireBytes());
        self::assertSame(RecommendationRunLog::VERDICT_TRANSPORT_FAILED, $log->getVerdict());
    }

    /**
     * DBAL's Connection::update() silently drops the WHERE clause on an
     * empty criteria array instead of raising — with a single row seeded,
     * a scoped and an unscoped UPDATE are observationally identical. Every
     * checkpoint/verdict write in RecordedCall must stay row-scoped in a
     * multi-user table, so this seeds a second user's run and log row and
     * proves they survive every write RecordedCall makes for the first
     * user's call.
     */
    public function testUpdatesStayScopedToTheOwningRunAndLog(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);

        [$otherRunId, $otherLogId] = $this->seedOtherUsersRunAndLog();

        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $this->clock->modify('+3 seconds');
        $call->streamProgressed(new CompletionStreamProgress('mine', 50));
        $call->finishUsable('final mine');

        $abortCall = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 2, [], 'm');
        $this->clock->modify('+3 seconds');
        $abortCall->streamProgressed(new CompletionStreamProgress('cut', 60));
        $abortCall->abortAfterTransportFailure('connection reset');

        $this->assertOtherUsersRowsUntouched($otherRunId, $otherLogId);
    }

    public function testASecondBeginForTheSamePhaseCountsTheAttempt(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm')->finishUnusable('bad');
        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        $rows = $this->logs()->listForUser($this->user);
        self::assertSame([1, 2], array_column($rows, 'attempt'));
    }

    /**
     * @return array{0: int, 1: int} the other user's run id and log id
     */
    private function seedOtherUsersRunAndLog(): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $otherUser = (new UserFactory($this->em, $hasher))->create('recorder-other@example.test');

        $otherRun = new RecommendationRun($otherUser, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->em->persist($otherRun);
        $otherLog = new RecommendationRunLog(
            $otherRun,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'other request',
            new \DateTimeImmutable('2026-08-08T09:00:00Z'),
        );
        $this->em->persist($otherLog);
        $this->em->flush();

        $otherRunId = $otherRun->getId();
        $otherLogId = $otherLog->getId();
        self::assertNotNull($otherRunId);
        self::assertNotNull($otherLogId);

        $connection = $this->em->getConnection();
        $connection->update('recommendation_run', ['streamed_chars' => 777], ['id' => $otherRunId]);
        $connection->update(
            'recommendation_run_log',
            ['response_text' => 'other original text', 'verdict' => RecommendationRunLog::VERDICT_USABLE],
            ['id' => $otherLogId],
        );

        return [$otherRunId, $otherLogId];
    }

    private function assertOtherUsersRowsUntouched(int $otherRunId, int $otherLogId): void
    {
        $this->em->clear();

        $otherRun = $this->em->find(RecommendationRun::class, $otherRunId);
        self::assertNotNull($otherRun);
        self::assertSame(777, $otherRun->getStreamedChars());

        $otherLog = $this->em->find(RecommendationRunLog::class, $otherLogId);
        self::assertNotNull($otherLog);
        self::assertSame('other original text', $otherLog->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_USABLE, $otherLog->getVerdict());
    }

    private function logs(): RecommendationRunLogRepository
    {
        return $this->logs;
    }

    private function freshLog(int $id): RecommendationRunLog
    {
        $this->em->clear();

        /** @var RecommendationRunLog $log */
        $log = $this->em->find(RecommendationRunLog::class, $id);

        return $log;
    }
}
