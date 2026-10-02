<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev;

use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallVerdict;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\RecommendationEngineSwitchFailure;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubSystemOneClient;

final class JevRecommendationEngineTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('jev-engine@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    /** A poll tick never waits: the 429 defers the run, halves its concurrency and strikes nothing. */
    public function testAPollWaveThatMeetsA429DefersWithoutAStrike(): void
    {
        $this->startRunAfterTheWarmUp(101, TickDriver::Poll);
        $this->systemOne()->queueFailure(new RetryableProviderException(429, 20));

        $report = $this->advancer()->advance($this->owner, TickDriver::Poll);

        $run = $this->activeRun();
        self::assertSame('running', $report->status);
        self::assertSame(0, $run->getTransportFailures());
        self::assertNotNull($run->getRetryNotBefore());
        self::assertSame(1, $run->getProgress()->batchesDone);
        self::assertSame(2, $run->getWaveConcurrencyCap(4));
        self::assertSame('Provider rate limited; deferring.', $this->lastLog()->getErrorDetail());
    }

    /** A deferral settles the wave unbanked, yet the sibling that already answered bills its paid reply. */
    public function testAPollWaveThatDefersStillBillsTheAnswerItGot(): void
    {
        $this->startRunAfterTheWarmUp(301, TickDriver::Poll);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.7);
        $this->systemOne()->queueFailure(new RetryableProviderException(429, 20));

        $this->advancer()->advance($this->owner, TickDriver::Poll);

        $run = $this->activeRun();
        self::assertSame(1, $run->getProgress()->batchesDone);
        self::assertSame(2 * StubSystemOneClient::COST_NANO_CREDITS, $run->getCostNanoCredits());
        $answered = $this->logs($run)[1];
        self::assertSame(StubSystemOneClient::REQUEST_ID, $answered->getRequestId());
        self::assertSame('Provider rate limited; deferring.', $answered->getErrorDetail());
    }

    /** A worker tick waits a 529 out, re-sends only the limited request, and banks the wave. */
    public function testAWorkerWaveWaitsOutA529AndBanksTheRetry(): void
    {
        $this->startRunAfterTheWarmUp(101, TickDriver::Worker);
        $this->systemOne()->queueFailure(new RetryableProviderException(529, 0));
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.8);

        $this->advancer()->advance($this->owner, TickDriver::Worker);

        $run = $this->activeRun();
        self::assertSame(2, $run->getProgress()->batchesDone);
        self::assertSame(0, $run->getTransportFailures());
        self::assertSame(2, $run->getWaveConcurrencyCap(4));
        self::assertCount(3, $this->systemOne()->requests());   // warm-up, the limited one, its re-send
        self::assertSame(2 * StubSystemOneClient::COST_NANO_CREDITS, $run->getCostNanoCredits());
    }

    /** Three batches in one worker wave: every reply is judged, not only the first usable one. */
    public function testAWorkerWaveBanksEveryBatchItSent(): void
    {
        $this->startRunAfterTheWarmUp(301, TickDriver::Worker);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.7);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.6);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);

        $this->advancer()->advance($this->owner, TickDriver::Worker);

        self::assertSame(4, $this->activeRun()->getProgress()->batchesDone);
    }

    /** One failure in the middle of a wave banks nothing, yet every answered sibling still bills what it cost. */
    public function testAFailureAmidAWaveBanksNothingAndBillsEveryAnswer(): void
    {
        $this->startRunAfterTheWarmUp(301, TickDriver::Worker);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.7);
        $this->systemOne()->queueFailure(new ProviderUnreachableException('That provider is down.'));
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);

        try {
            $this->advancer()->advance($this->owner, TickDriver::Worker);
            self::fail('The wave failure must propagate.');
        } catch (ProviderUnreachableException) {
        }

        $run = $this->activeRun();
        self::assertSame(1, $run->getProgress()->batchesDone);
        self::assertSame(3 * StubSystemOneClient::COST_NANO_CREDITS, $run->getCostNanoCredits());
        self::assertSame(
            array_fill(0, 3, 'That provider is down.'),
            array_map(
                static fn (RecommendationRunLog $log): ?string => $log->getErrorDetail(),
                \array_slice($this->logs($run), 1),
            ),
        );
    }

    /** A run cancelled while its wave was out banks none of the answers. */
    public function testARunCancelledDuringTheWaveBanksNothing(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);
        $runId = $this->activeRun()->requireId();
        $connection = $this->entityManager->getConnection();
        $this->systemOne()->queueNouls(static function (int $entryId) use ($connection, $runId): float {
            $connection->executeStatement(
                'UPDATE recommendation_run SET status = ? WHERE id = ?',
                [RunStatus::Cancelled->value, $runId],
            );

            return 0.9;
        });

        $this->advancer()->advance($this->owner);

        self::assertSame(0, $this->latestRun()->getProgress()->batchesDone);
    }

    public function testARefusedKeyStrikesTheRunAndSettlesItsRow(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);   // snapshot
        $this->systemOne()->queueFailure(new CredentialsRejectedException('That provider refused the API key.'));

        try {
            $this->advancer()->advance($this->owner);
            self::fail('A refused key must propagate.');
        } catch (CredentialsRejectedException) {
        }

        self::assertSame(1, $this->activeRun()->getTransportFailures());
        self::assertSame(CallVerdict::TransportFailed, $this->lastLog()->getVerdict());
        self::assertSame('That provider refused the API key.', $this->lastLog()->getErrorDetail());
    }

    /** A key sealed for another account cannot be opened: the wave settles its row before the error propagates. */
    public function testAnUnreadableKeySettlesTheRowItOpened(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);
        $this->sealKeyForAnotherAccount();

        try {
            $this->advancer()->advance($this->owner);
            self::fail('An unreadable key must propagate.');
        } catch (AiKeyUnreadableException) {
        }

        self::assertSame(CallVerdict::TransportFailed, $this->lastLog()->getVerdict());
        self::assertSame('The stored API key cannot be opened.', $this->lastLog()->getErrorDetail());
    }

    /** A 422 repeats, so the run fails after its strikes with the field System One named. */
    public function testARejectedRequestFailsTheRunWithTheProvidersDetail(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);
        for ($strike = 0; $strike < RecommendationRun::MAX_TRANSPORT_FAILURES; $strike++) {
            $this->systemOne()->queueFailure(new ProviderUnreachableException(
                'That provider refused the request (status 422): [{"msg":"state too long"}]',
            ));
            try {
                $this->advancer()->advance($this->owner);
            } catch (ProviderUnreachableException) {
            }
        }

        $run = $this->latestRun();
        self::assertSame('failed', $run->getStatus()->value);
        self::assertStringContainsString('status 422', (string) $run->getError());
        self::assertStringContainsString('state too long', (string) $run->getError());
    }

    /** A reply missing a candidate's Noul is retried in the tick; after MAX_ATTEMPTS the batch yields no winners. */
    public function testAnUnusableReplyIsRetriedThenTheBatchYieldsNothing(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->systemOne()->queueBody('{"model":"jev-1.13.0","answers":{}}');
        }

        $this->advancer()->advance($this->owner);   // the wave: three rounds, no winners
        $this->advancer()->advance($this->owner);   // the finalising tick

        $run = $this->latestRun();
        self::assertSame('completed', $run->getStatus()->value);
        self::assertSame(0, $this->itemCount($run));
        self::assertSame(
            [CallVerdict::Unusable, CallVerdict::Unusable, CallVerdict::Unusable],
            array_map(static fn (RecommendationRunLog $log): ?CallVerdict => $log->getVerdict(), $this->logs($run)),
        );
    }

    public function testAnLlmRunWhoseConnectionSwitchedToJevFailsWithoutAnyCall(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->chooseModel('gpt-4o', new \DateTimeImmutable('2026-10-02 09:00:00'), 128_000);
        $this->entityManager->flush();
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->chooseModel('jev-latest', new \DateTimeImmutable('2026-10-02 09:10:00'), 64_000);
        $this->entityManager->flush();

        $this->advancer()->advance($this->owner);

        $run = $this->latestRun();
        self::assertSame('failed', $run->getStatus()->value);
        self::assertSame(RecommendationEngineSwitchFailure::MESSAGE, $run->getError());
        self::assertSame([], $this->systemOne()->requests());
    }

    /** Snapshot, then the one-request warm-up wave banks the first 100-question batch; 101 candidates leave one more. */
    private function startRunAfterTheWarmUp(int $candidateCount, TickDriver $driver): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, $candidateCount);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->setBatchConcurrency(4);
        $this->entityManager->flush();
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner, $driver);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.4);
        $this->advancer()->advance($this->owner, $driver);
        self::assertSame(1, $this->activeRun()->getProgress()->batchesDone);
    }

    private function sealKeyForAnotherAccount(): void
    {
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $now = new \DateTimeImmutable('2026-10-02 09:00:00');
        $connection->replaceConnection(
            $connection->getBaseUrl(),
            $cipher->seal($this->owner->requireId() + 1, 'sk-throwaway1234'),
            '1234',
            $now,
        );
        $connection->chooseModel('jev-latest', $now, 64_000);
        $this->entityManager->flush();
    }

    /** Refreshed, not cleared: the owner stays managed for the next tick, and the row is read as the tick left it. */
    private function activeRun(): RecommendationRun
    {
        $run = $this->runs()->findActiveForUser($this->owner);
        self::assertNotNull($run);
        $this->entityManager->refresh($run);

        return $run;
    }

    private function latestRun(): RecommendationRun
    {
        $run = $this->runs()->findLatestForUser($this->owner);
        self::assertNotNull($run);
        $this->entityManager->refresh($run);

        return $run;
    }

    private function lastLog(): RecommendationRunLog
    {
        $logs = $this->logs($this->latestRun());
        $last = end($logs);
        self::assertInstanceOf(RecommendationRunLog::class, $last);

        return $last;
    }

    /**
     * Refreshed: the recorder settles each row by an UPDATE, behind the copy the identity map holds.
     *
     * @return list<RecommendationRunLog>
     */
    private function logs(RecommendationRun $run): array
    {
        /** @var list<RecommendationRunLog> $logs */
        $logs = $this->entityManager->getRepository(RecommendationRunLog::class)
            ->findBy(['run' => $run->requireId()], ['id' => 'ASC']);
        foreach ($logs as $log) {
            $this->entityManager->refresh($log);
        }

        return $logs;
    }

    private function itemCount(RecommendationRun $run): int
    {
        return $this->entityManager->getRepository(RecommendationItem::class)->count(['run' => $run->requireId()]);
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $runs */
        $runs = $this->entityManager->getRepository(RecommendationRun::class);

        return $runs;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    private function systemOne(): StubSystemOneClient
    {
        /** @var StubSystemOneClient $client */
        $client = self::getContainer()->get(StubSystemOneClient::class);

        return $client;
    }
}
