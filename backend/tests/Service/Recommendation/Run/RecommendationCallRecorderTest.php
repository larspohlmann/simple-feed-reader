<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\ProfileRunTrigger;
use App\Repository\RecommendationCallRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Model\ProviderCallUsageModel;
use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Llm\Completion\Model\JsonSchemaModel;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Run\Support\RenderedCompletionRequest;
use App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory;
use App\Service\Recommendation\Run\Model\CallProgressModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\RecommendationCallRecorder;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
final class RecommendationCallRecorderTest extends DbTestCase
{
    private User $user;
    private RecommendationRun $run;
    private MockClock $clock;
    private RecommendationCallRecorder $recorder;
    private RecommendationRunLogRepository $logs;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->entityManager, $hasher);
        $this->user = $factory->create('recorder-owner@example.test');

        $this->run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();

        $this->clock = new MockClock('2026-08-08T10:00:00Z');

        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        $this->logs = $logs;

        $this->recorder = new RecommendationCallRecorder(
            $this->entityManager,
            new RecommendationCallRepository($this->entityManager->getConnection()),
            $this->clock,
            new RecommendationRunLogFactory($this->logs, $this->clock),
        );
    }

    /**
     * The row is written for every run, debugged or not: the ETA reads this log as its timing history, so the request
     * body lands the moment the call goes out.
     */
    public function testBeginPersistsTheRequestBodyImmediatelyRegardlessOfTheDebugSwitch(): void
    {
        $this->recorder->begin(
            $this->run,
            CallSlotModel::batch(2),
            $this->request([['role' => 'user', 'content' => 'hi']]),
        );

        $rows = $this->logRows();
        self::assertCount(1, $rows);
        self::assertSame(CallPhase::Batch, $rows[0]['phase']);
        self::assertSame(2, $rows[0]['batchNumber']);
        self::assertSame(1, $rows[0]['attempt']);
        self::assertNull($rows[0]['verdict']);
        $log = $this->freshLog($rows[0]['id']);
        self::assertStringContainsString('"model": "m"', $log->getRequestBody());
        self::assertStringContainsString('"content": "hi"', $log->getRequestBody());
        self::assertEquals($this->clock->now(), $log->getCreatedAt());
    }

    public function testCheckpointsAreThrottledToTheInterval(): void
    {
        $call = $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]));
        $logId = $this->logRows()[0]['id'];

        $call->progressed(new CallProgressModel('He', 40));
        self::assertSame(
            '',
            $this->freshLog($logId)->getResponseText(),
            'first growth inside the interval is not written',
        );

        $this->clock->modify('+3 seconds');
        $call->progressed(new CallProgressModel('Hello', 90));

        self::assertSame('Hello', $this->freshLog($logId)->getResponseText());
    }

    public function testCheckpointUpdatesTheLivenessCounter(): void
    {
        $call = $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]));

        $this->clock->modify('+3 seconds');
        $call->progressed(new CallProgressModel('He', 1_234));

        $runId = $this->run->getId();
        self::assertNotNull($runId);
        $this->entityManager->clear();
        $freshRun = $this->entityManager->find(RecommendationRun::class, $runId);
        self::assertSame(1_234, $freshRun?->getStreamedChars());
    }

    public function testFinishUsableStoresTextVerdictAndResetsLiveness(): void
    {
        $call = $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]));
        $logId = $this->logRows()[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->progressed(new CallProgressModel('partial', 7_000));

        $call->finishUsable('{"recommendations": []}');

        $log = $this->freshLog($logId);
        self::assertSame('{"recommendations": []}', $log->getResponseText());
        self::assertSame(CallVerdict::Usable, $log->getVerdict());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
        $freshRun = $this->entityManager->find(RecommendationRun::class, $this->run->getId());
        self::assertSame(0, $freshRun?->getStreamedChars());
    }

    public function testAbortKeepsThePartialTextWithTransportVerdictAndTheTransportMessage(): void
    {
        $call = $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]));
        $logId = $this->logRows()[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->progressed(new CallProgressModel('cut off', 9_001));

        $call->abortAfterTransportFailure('cURL error 28');

        $log = $this->freshLog($logId);
        self::assertSame('cut off', $log->getResponseText());
        self::assertSame(CallVerdict::TransportFailed, $log->getVerdict());
        self::assertSame(9_001, $log->getWireBytes());
        self::assertSame('cURL error 28', $log->getErrorDetail());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
    }

    /**
     * A reasoning model can stream megabytes and never answer: without the byte count, that row reads like a provider
     * that said nothing at all.
     */
    public function testAnAbortRecordsTheBytesEvenWhenNothingWasAnswered(): void
    {
        $call = $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]));
        $logId = $this->logRows()[0]['id'];

        // Inside the checkpoint interval on purpose: no write has happened,
        // so the count can only reach the row if every report tracks it.
        $call->progressed(new CallProgressModel('', 1_900_000));
        $call->abortAfterTransportFailure(null);

        $log = $this->freshLog($logId);
        self::assertSame('', $log->getResponseText());
        self::assertSame(1_900_000, $log->getWireBytes());
        self::assertSame(CallVerdict::TransportFailed, $log->getVerdict());
    }

    /**
     * DBAL's update() drops the WHERE clause on empty criteria instead of raising, so a second user's run and log row
     * must survive every write RecordedCall makes for the first user's call.
     */
    public function testUpdatesStayScopedToTheOwningRunAndLog(): void
    {
        [$otherRunId, $otherLogId] = $this->seedOtherUsersRunAndLog();

        $call = $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]));
        $this->clock->modify('+3 seconds');
        $call->progressed(new CallProgressModel('mine', 50));
        $call->finishUsable('final mine');

        $abortCall = $this->recorder->begin($this->run, CallSlotModel::batch(2), $this->request([]));
        $this->clock->modify('+3 seconds');
        $abortCall->progressed(new CallProgressModel('cut', 60));
        $abortCall->abortAfterTransportFailure('connection reset');

        $this->assertOtherUsersRowsUntouched($otherRunId, $otherLogId);
    }

    public function testASecondBeginForTheSamePhaseCountsTheAttempt(): void
    {
        $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]))->finishUnusable('bad');
        $this->recorder->begin($this->run, CallSlotModel::batch(1), $this->request([]));

        $rows = $this->logRows();
        self::assertSame([1, 2], array_column($rows, 'attempt'));
    }

    public function testAProfileRunCallOpensADistillRowUnderTheProfileRun(): void
    {
        $profileRun = $this->runningProfileRun();

        $this->recorder->beginForProfileRun($profileRun, $this->request([['role' => 'user', 'content' => 'history']]));

        $rows = $this->logs->listForProfileRun($this->user, $profileRun->requireId());
        self::assertCount(1, $rows);
        self::assertSame(CallPhase::Distill, $rows[0]['phase']);
        self::assertNull($rows[0]['batchNumber']);
        self::assertSame(1, $rows[0]['attempt']);
        self::assertSame($profileRun->requireId(), $rows[0]['runId']);
        self::assertSame([], $this->logRows(), 'a profile-run row is not the recommendation run\'s');
    }

    public function testASecondProfileRunCallCountsTheAttempt(): void
    {
        $profileRun = $this->runningProfileRun();
        $this->recorder->beginForProfileRun($profileRun, $this->request([]));

        $this->recorder->beginForProfileRun($profileRun, $this->request([]));

        $rows = $this->logs->listForProfileRun($this->user, $profileRun->requireId());
        self::assertSame([1, 2], array_column($rows, 'attempt'));
    }

    public function testAProfileRunCallsLivenessAndUsageLandOnTheProfileRun(): void
    {
        $profileRun = $this->runningProfileRun();
        $call = $this->recorder->beginForProfileRun($profileRun, $this->request([]));

        $this->clock->modify('+3 seconds');
        $usage = new ProviderCallUsageModel(910, 37, 0, 0, 1_500);
        $call->progressed(new CallProgressModel('{"prof', 2_345, usage: $usage));

        self::assertSame(2_345, $this->profileRunColumns($profileRun)['streamed_chars']);

        $call->finishUsable('{"profile":"Likes maps."}');

        $columns = $this->profileRunColumns($profileRun);
        self::assertSame(0, $columns['streamed_chars']);
        self::assertSame(910, $columns['prompt_tokens']);
        self::assertSame(1_500, $columns['cost_nano_credits']);
        self::assertSame(0, $this->runColumns()['prompt_tokens'], 'the recommendation run is not billed');
    }

    public function testAProfileRunCallBillsOnlyItsOwnProfileRun(): void
    {
        $profileRun = $this->runningProfileRun();
        $otherProfileRun = $this->runningProfileRun();
        $call = $this->recorder->beginForProfileRun($profileRun, $this->request([]));

        $call->progressed(new CallProgressModel('{"prof', 10, usage: new ProviderCallUsageModel(910, 37, 0, 0, 1_500)));
        $call->finishUsable('{"profile":"Likes maps."}');

        $columns = $this->profileRunColumns($otherProfileRun);
        self::assertSame(0, $columns['prompt_tokens']);
        self::assertNull($columns['cost_nano_credits']);
    }

    private function runningProfileRun(): ProfileRun
    {
        $createdAt = new \DateTimeImmutable('2026-10-03T09:00:00Z');
        $profileRun = new ProfileRun($this->user, ProfileRunTrigger::Manual, $createdAt);
        $profileRun->start('fingerprint-1', 'llm.example.test', 'qwen3-14b');
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    /** @return array{streamed_chars: int, prompt_tokens: int, cost_nano_credits: ?int} */
    private function profileRunColumns(ProfileRun $profileRun): array
    {
        /** @var array{streamed_chars: int|string, prompt_tokens: int|string, cost_nano_credits: int|string|null} $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT streamed_chars, prompt_tokens, cost_nano_credits FROM profile_run WHERE id = ?',
            [$profileRun->requireId()],
        );

        return [
            'streamed_chars' => (int) $row['streamed_chars'],
            'prompt_tokens' => (int) $row['prompt_tokens'],
            'cost_nano_credits' => null === $row['cost_nano_credits'] ? null : (int) $row['cost_nano_credits'],
        ];
    }

    /** @return array{prompt_tokens: int} */
    private function runColumns(): array
    {
        /** @var array{prompt_tokens: int|string} $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT prompt_tokens FROM recommendation_run WHERE id = ?',
            [$this->run->requireId()],
        );

        return ['prompt_tokens' => (int) $row['prompt_tokens']];
    }

    /**
     * @return array{0: int, 1: int} the other user's run id and log id
     */
    private function seedOtherUsersRunAndLog(): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $otherUser = (new UserFactory($this->entityManager, $hasher))->create('recorder-other@example.test');

        $otherRun = new RecommendationRun($otherUser, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->entityManager->persist($otherRun);
        $otherLog = RecommendationRunLog::forRun(
            $otherRun,
            CallPhase::Batch,
            1,
            1,
            'other request',
            new \DateTimeImmutable('2026-08-08T09:00:00Z'),
        );
        $this->entityManager->persist($otherLog);
        $this->entityManager->flush();

        $otherRunId = $otherRun->getId();
        $otherLogId = $otherLog->getId();
        self::assertNotNull($otherRunId);
        self::assertNotNull($otherLogId);

        $connection = $this->entityManager->getConnection();
        $connection->update('recommendation_run', ['streamed_chars' => 777], ['id' => $otherRunId]);
        $connection->update(
            'recommendation_run_log',
            ['response_text' => 'other original text', 'verdict' => CallVerdict::Usable->value],
            ['id' => $otherLogId],
        );

        return [$otherRunId, $otherLogId];
    }

    private function assertOtherUsersRowsUntouched(int $otherRunId, int $otherLogId): void
    {
        $this->entityManager->clear();

        $otherRun = $this->entityManager->find(RecommendationRun::class, $otherRunId);
        self::assertNotNull($otherRun);
        self::assertSame(777, $otherRun->getStreamedChars());

        $otherLog = $this->entityManager->find(RecommendationRunLog::class, $otherLogId);
        self::assertNotNull($otherLog);
        self::assertSame('other original text', $otherLog->getResponseText());
        self::assertSame(CallVerdict::Usable, $otherLog->getVerdict());
    }

    /**
     * The rows of the run under test: the log keeps ten runs, so a read names one.
     *
     * @return list<DebugLogRow>
     */
    private function logRows(): array
    {
        return $this->logs()->listForRun($this->user, $this->run->requireId());
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function request(array $messages): string
    {
        return RenderedCompletionRequest::of(new CompletionRequestModel(
            'm',
            $messages,
            1024,
            new JsonSchemaModel('test', ['type' => 'object']),
            Reasoning::Allowed,
        ));
    }

    private function logs(): RecommendationRunLogRepository
    {
        return $this->logs;
    }

    private function freshLog(int $id): RecommendationRunLog
    {
        $this->entityManager->clear();

        /** @var RecommendationRunLog $log */
        $log = $this->entityManager->find(RecommendationRunLog::class, $id);

        return $log;
    }
}
