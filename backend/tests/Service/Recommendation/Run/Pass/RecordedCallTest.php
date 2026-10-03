<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Pass;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Repository\CallingRun;
use App\Repository\RecommendationCallRepository;
use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Ai\Model\ProviderCallUsageModel;
use App\Service\Recommendation\Llm\Completion\Support\CompletionFinishReason;
use App\Service\Recommendation\Run\Model\CallProgressModel;
use App\Service\Recommendation\Run\Pass\RecordedCall;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * RecordedCall's own DBAL writes, apart from RecommendationCallRecorder: the recorder decides whether a log row
 * exists, RecordedCall what a settled call writes into it.
 */
final class RecordedCallTest extends DbTestCase
{
    use ReloadsEntities;

    private User $user;
    private RecommendationRun $run;
    private RecommendationRunLog $log;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('recorded-call-owner@example.test');

        $this->run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->entityManager->persist($this->run);

        $this->log = RecommendationRunLog::forRun(
            $this->run,
            CallPhase::Batch,
            1,
            1,
            'the request',
            new \DateTimeImmutable('2026-08-08T09:59:00Z'),
        );
        $this->entityManager->persist($this->log);
        $this->entityManager->flush();

        $this->clock = new MockClock('2026-08-08T10:00:00Z');
    }

    public function testFinishUsableWritesTheFinishedAtTimestamp(): void
    {
        $call = $this->call();

        $call->finishUsable('the answer');

        $log = $this->reload($this->log);
        self::assertSame('the answer', $log->getResponseText());
        self::assertSame(CallVerdict::Usable, $log->getVerdict());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
        self::assertNull($log->getErrorDetail());
    }

    public function testFinishUsableWritesTheWireByteCount(): void
    {
        $call = $this->call();
        $call->progressed(new CallProgressModel('partial answer', 4_096));

        $call->finishUsable('the answer');

        self::assertSame(4_096, $this->reload($this->log)->getWireBytes());
    }

    public function testAbortAfterTransportFailureWritesFinishedAtErrorDetailAndTheTransportVerdict(): void
    {
        $call = $this->call();

        $call->abortAfterTransportFailure('cURL error 28');

        $log = $this->reload($this->log);
        self::assertSame(CallVerdict::TransportFailed, $log->getVerdict());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
        self::assertSame('cURL error 28', $log->getErrorDetail());
    }

    public function testAbortAfterTransportFailureAcceptsANullMessage(): void
    {
        $call = $this->call();

        $call->abortAfterTransportFailure(null);

        self::assertNull($this->reload($this->log)->getErrorDetail());
    }

    public function testASettledCallRecordsTheProvidersFinishReason(): void
    {
        $call = $this->call();
        $call->progressed(new CallProgressModel('partial answer', 100, 'length'));

        $call->finishUsable('the answer');

        self::assertSame('length', $this->reload($this->log)->getFinishReason());
    }

    /**
     * The finish reason stamped before the stream died explains the death: a `length` turns an empty "answered
     * without a completion" row into "truncated by max_tokens".
     */
    public function testATransportFailureKeepsTheFinishReasonSeenBeforeItDied(): void
    {
        $call = $this->call();
        $call->progressed(new CallProgressModel('', 100, 'length'));

        $call->abortAfterTransportFailure('cURL error 28');

        self::assertSame('length', $this->reload($this->log)->getFinishReason());
    }

    public function testACallThatNeverHeardAFinishReasonRecordsNone(): void
    {
        $call = $this->call();

        $call->finishUsable('the answer');

        self::assertNull($this->reload($this->log)->getFinishReason());
    }

    public function testACallTheProviderEndedWithAnErrorWasCutByTheProvider(): void
    {
        $call = $this->call();
        $call->progressed(new CallProgressModel('{"recommendations": [', 100, 'error'));
        $call->progressed(new CallProgressModel('{"recommendations": [', 120));

        self::assertTrue(CompletionFinishReason::cutByProvider($call->finishReason()));
    }

    public function testACallThatStoppedOnItsOwnWasNotCutByTheProvider(): void
    {
        $call = $this->call();
        $call->progressed(new CallProgressModel('{}', 100, 'stop'));

        self::assertFalse(CompletionFinishReason::cutByProvider($call->finishReason()));
    }

    public function testBanksTheProvidersUsageOntoTheRunWhenTheCallSettles(): void
    {
        $call = $this->call();

        $call->progressed(new CallProgressModel('{}', 100, 'stop', new ProviderCallUsageModel(
            promptTokens: 1200,
            completionTokens: 340,
            reasoningTokens: 90,
            cachedTokens: 1100,
            costNanoCredits: 41_230_000,
        )));
        $call->finishUsable('{}');

        self::assertSame([
            'promptTokens' => 1200,
            'completionTokens' => 340,
            'reasoningTokens' => 90,
            'cachedTokens' => 1100,
            'costNanoCredits' => 41_230_000,
        ], $this->runTotals());
    }

    public function testBanksTheUsageOfACallThatFailedInTransport(): void
    {
        $call = $this->call();

        $call->progressed(new CallProgressModel('', 100, null, new ProviderCallUsageModel(
            promptTokens: 900,
            completionTokens: 0,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: 2000,
        )));
        $call->abortAfterTransportFailure('That address did not answer.');

        self::assertSame(900, $this->runTotals()['promptTokens']);
    }

    public function testLeavesTheCostNullWhenTheProviderReportedNone(): void
    {
        $call = $this->call();

        $call->progressed(new CallProgressModel('{}', 100, 'stop', new ProviderCallUsageModel(
            promptTokens: 40,
            completionTokens: 9,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: null,
        )));
        $call->finishUsable('{}');

        self::assertSame(40, $this->runTotals()['promptTokens']);
        self::assertNull($this->runTotals()['costNanoCredits']);
    }

    public function testBanksOneCallOnceHoweverManySettlePathsReachIt(): void
    {
        $call = $this->call();

        $call->progressed(new CallProgressModel('', 100, null, new ProviderCallUsageModel(
            promptTokens: 900,
            completionTokens: 0,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: 2000,
        )));
        $call->abortAfterTransportFailure('That address did not answer.');
        $call->abortAfterTransportFailure('That address did not answer.');

        self::assertSame(900, $this->runTotals()['promptTokens']);
        self::assertSame(2000, $this->runTotals()['costNanoCredits']);
    }

    public function testBanksNothingWhenTheProviderSentNoUsageAtAll(): void
    {
        $call = $this->call();

        $call->progressed(new CallProgressModel('{}', 100, 'stop'));
        $call->finishUsable('{}');

        self::assertSame(0, $this->runTotals()['promptTokens']);
        self::assertNull($this->runTotals()['costNanoCredits']);
    }

    /**
     * A second call against the same run tells `SET x = x + :n` from `SET x = :n`: the token sum proves the addition,
     * the cost sum that COALESCE both initialises the column and adds to it.
     */
    public function testBanksTwoCallsUsageAsASumNotAnOverwrite(): void
    {
        $first = $this->call();
        $first->progressed(new CallProgressModel('{}', 100, 'stop', new ProviderCallUsageModel(
            promptTokens: 1000,
            completionTokens: 200,
            reasoningTokens: 50,
            cachedTokens: 300,
            costNanoCredits: 10_000,
        )));
        $first->finishUsable('{}');

        $second = $this->call();
        $second->progressed(new CallProgressModel('{}', 100, 'stop', new ProviderCallUsageModel(
            promptTokens: 400,
            completionTokens: 90,
            reasoningTokens: 10,
            cachedTokens: 20,
            costNanoCredits: 3_000,
        )));
        $second->finishUsable('{}');

        self::assertSame([
            'promptTokens' => 1400,
            'completionTokens' => 290,
            'reasoningTokens' => 60,
            'cachedTokens' => 320,
            'costNanoCredits' => 13_000,
        ], $this->runTotals());
    }

    /** A later progress report without usage must not erase the usage already seen (progressed()'s `??`). */
    public function testKeepsTheUsageSeenBeforeALaterReportArrivesWithoutIt(): void
    {
        $call = $this->call();

        $call->progressed(new CallProgressModel('{}', 100, 'stop', new ProviderCallUsageModel(
            promptTokens: 500,
            completionTokens: 60,
            reasoningTokens: 5,
            cachedTokens: 0,
            costNanoCredits: 7_000,
        )));
        $call->progressed(new CallProgressModel('{}', 200));
        $call->finishUsable('{}');

        self::assertSame(500, $this->runTotals()['promptTokens']);
        self::assertSame(7000, $this->runTotals()['costNanoCredits']);
    }

    /** A reply that arrives whole: its size, its usage on the run, and the provider's receipt on its row. */
    public function testAWholeReplyRecordsItsReceiptAndBanksItsUsageOnTheRun(): void
    {
        $call = $this->call();

        $call->received(new ProviderCallReceiptModel(
            'req-91',
            'typesafe/jev-1.13-20260917',
            new ProviderCallUsageModel(
                promptTokens: 1200,
                completionTokens: 30,
                reasoningTokens: 0,
                cachedTokens: 0,
                costNanoCredits: 4_200_000,
            ),
        ), 812);
        $call->finishUsable('{"answers":{}}');

        $log = $this->reload($this->log);
        self::assertSame('req-91', $log->getRequestId());
        self::assertSame('typesafe/jev-1.13-20260917', $log->getAnsweringModel());
        self::assertSame(4_200_000, $log->getCostNanoCredits());
        self::assertSame(812, $log->getWireBytes());
        self::assertSame(1200, $this->runTotals()['promptTokens']);
        self::assertSame(4_200_000, $this->runTotals()['costNanoCredits']);
    }

    /** A wave a sibling's failure aborts still says what this call's answer was and what it cost. */
    public function testAnAbortedCallThatHadAnsweredKeepsItsReceipt(): void
    {
        $call = $this->call();

        $call->received(new ProviderCallReceiptModel('req-92', null, null), 64);
        $call->abortAfterTransportFailure('That provider refused the API key.');

        self::assertSame('req-92', $this->reload($this->log)->getRequestId());
    }

    private function call(): RecordedCall
    {
        $runId = $this->run->requireId();
        $logId = $this->log->requireId();

        $calls = new RecommendationCallRepository($this->entityManager->getConnection());

        return new RecordedCall($calls, $this->clock, CallingRun::recommendationRun($runId), $logId);
    }

    /** @return array{promptTokens: int, completionTokens: int, reasoningTokens: int, cachedTokens: int, costNanoCredits: ?int} */
    private function runTotals(): array
    {
        $runId = $this->run->getId();
        self::assertNotNull($runId);

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT prompt_tokens, completion_tokens, reasoning_tokens, cached_tokens, cost_nano_credits'
            . ' FROM recommendation_run WHERE id = :runId',
            ['runId' => $runId],
        );
        self::assertNotFalse($row);

        return [
            'promptTokens' => self::columnAsInt($row['prompt_tokens']),
            'completionTokens' => self::columnAsInt($row['completion_tokens']),
            'reasoningTokens' => self::columnAsInt($row['reasoning_tokens']),
            'cachedTokens' => self::columnAsInt($row['cached_tokens']),
            'costNanoCredits' => null === $row['cost_nano_credits']
                ? null
                : self::columnAsInt($row['cost_nano_credits']),
        ];
    }

    private static function columnAsInt(mixed $value): int
    {
        self::assertTrue(
            is_int($value) || is_string($value) || is_float($value),
            'Expected a numeric recommendation_run column value.',
        );

        return (int) $value;
    }
}
