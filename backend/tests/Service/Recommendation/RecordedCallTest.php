<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Service\Recommendation\CompletionStreamProgress;
use App\Service\Recommendation\CompletionUsage;
use App\Service\Recommendation\RecordedCall;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * RecordedCall's own DBAL writes, isolated from RecommendationCallRecorder
 * (#321): the recorder is what decides whether a log row exists at all, this
 * is what decides what a settled call writes into it once it does.
 */
final class RecordedCallTest extends DbTestCase
{
    private User $user;
    private RecommendationRun $run;
    private RecommendationRunLog $log;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('recorded-call-owner@example.test');

        $this->run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->em->persist($this->run);

        $this->log = new RecommendationRunLog(
            $this->run,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'the request',
            new \DateTimeImmutable('2026-08-08T09:59:00Z'),
        );
        $this->em->persist($this->log);
        $this->em->flush();

        $this->clock = new MockClock('2026-08-08T10:00:00Z');
    }

    public function testFinishUsableWritesTheFinishedAtTimestamp(): void
    {
        $call = $this->call();

        $call->finishUsable('the answer');

        $log = $this->freshLog();
        self::assertSame('the answer', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_USABLE, $log->getVerdict());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
        self::assertNull($log->getErrorDetail());
    }

    public function testAbortAfterTransportFailureWritesFinishedAtErrorDetailAndTheTransportVerdict(): void
    {
        $call = $this->call();

        $call->abortAfterTransportFailure('cURL error 28');

        $log = $this->freshLog();
        self::assertSame(RecommendationRunLog::VERDICT_TRANSPORT_FAILED, $log->getVerdict());
        self::assertEquals($this->clock->now(), $log->getFinishedAt());
        self::assertSame('cURL error 28', $log->getErrorDetail());
    }

    public function testAbortAfterTransportFailureAcceptsANullMessage(): void
    {
        $call = $this->call();

        $call->abortAfterTransportFailure(null);

        self::assertNull($this->freshLog()->getErrorDetail());
    }

    public function testASettledCallRecordsTheProvidersFinishReason(): void
    {
        $call = $this->call();
        $call->streamProgressed(new CompletionStreamProgress('partial answer', 100, 'length'));

        $call->finishUsable('the answer');

        self::assertSame('length', $this->freshLog()->getFinishReason());
    }

    /**
     * The reason the provider stamped before the stream died is exactly what
     * explains the death: a `length` here turns an empty "answered without a
     * completion" row into "the answer was truncated by max_tokens" (#327).
     */
    public function testATransportFailureKeepsTheFinishReasonSeenBeforeItDied(): void
    {
        $call = $this->call();
        $call->streamProgressed(new CompletionStreamProgress('', 100, 'length'));

        $call->abortAfterTransportFailure('cURL error 28');

        self::assertSame('length', $this->freshLog()->getFinishReason());
    }

    public function testACallThatNeverHeardAFinishReasonRecordsNone(): void
    {
        $call = $this->call();

        $call->finishUsable('the answer');

        self::assertNull($this->freshLog()->getFinishReason());
    }

    public function testBanksTheProvidersUsageOntoTheRunWhenTheCallSettles(): void
    {
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop', new CompletionUsage(
            promptTokens: 1200,
            completionTokens: 340,
            reasoningTokens: 90,
            cachedTokens: 1100,
            costNanoCredits: 41_230_000,
        )));
        $call->settle('{}', true);

        self::assertSame([
            'promptTokens' => 1200,
            'completionTokens' => 340,
            'reasoningTokens' => 90,
            'cachedTokens' => 1100,
            'costNanoCredits' => 41_230_000,
        ], $this->runTotals());
    }

    public function testBanksTheUsageWithTheDebugSwitchOff(): void
    {
        $call = $this->recordedCall(logId: null);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop', new CompletionUsage(
            promptTokens: 10,
            completionTokens: 2,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: 5000,
        )));
        $call->settle('{}', true);

        self::assertSame(10, $this->runTotals()['promptTokens']);
        self::assertSame(5000, $this->runTotals()['costNanoCredits']);
    }

    public function testBanksTheUsageOfACallThatFailedInTransport(): void
    {
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('', 100, null, new CompletionUsage(
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
        $call = $this->recordedCall(logId: null);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop', new CompletionUsage(
            promptTokens: 40,
            completionTokens: 9,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: null,
        )));
        $call->settle('{}', true);

        self::assertSame(40, $this->runTotals()['promptTokens']);
        self::assertNull($this->runTotals()['costNanoCredits']);
    }

    public function testBanksOneCallOnceHoweverManySettlePathsReachIt(): void
    {
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('', 100, null, new CompletionUsage(
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
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop'));
        $call->settle('{}', true);

        self::assertSame(0, $this->runTotals()['promptTokens']);
        self::assertNull($this->runTotals()['costNanoCredits']);
    }

    private function call(): RecordedCall
    {
        $runId = $this->run->getId();
        $logId = $this->log->getId();
        self::assertNotNull($runId);
        self::assertNotNull($logId);

        return new RecordedCall($this->em->getConnection(), $this->clock, $runId, $logId);
    }

    /**
     * Unlike call(), $logId is not the real log row's id: these tests exist
     * to prove bankUsage() runs before the $logId guard, so an arbitrary
     * value that is null exactly when the caller wants "debug off" serves
     * that better than the fixture's own log, whose id is real either way.
     */
    private function recordedCall(?int $logId): RecordedCall
    {
        $runId = $this->run->getId();
        self::assertNotNull($runId);

        return new RecordedCall($this->em->getConnection(), $this->clock, $runId, $logId);
    }

    /** @return array{promptTokens: int, completionTokens: int, reasoningTokens: int, cachedTokens: int, costNanoCredits: ?int} */
    private function runTotals(): array
    {
        $runId = $this->run->getId();
        self::assertNotNull($runId);

        $row = $this->em->getConnection()->fetchAssociative(
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

    /**
     * fetchAssociative() hands back a row typed as array<string, mixed>, and a
     * bare (int) cast on mixed is exactly what PHPStan max forbids -- this is
     * the narrowing step that makes the cast legal, not a workaround for it.
     */
    private static function columnAsInt(mixed $value): int
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            throw new \RuntimeException('Expected a numeric recommendation_run column value.');
        }

        return (int) $value;
    }

    private function freshLog(): RecommendationRunLog
    {
        $this->em->clear();

        $id = $this->log->getId();
        self::assertNotNull($id);

        /** @var RecommendationRunLog $log */
        $log = $this->em->find(RecommendationRunLog::class, $id);

        return $log;
    }
}
