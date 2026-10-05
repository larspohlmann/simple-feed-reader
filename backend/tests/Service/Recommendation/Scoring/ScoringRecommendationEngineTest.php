<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Entity\Entry;
use App\Entity\ModelDescriptor;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Enum\CallVerdict;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\TickPhases;
use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;
use App\Service\Recommendation\Scoring\ScoringRecommendationEngine;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Support\TokenEstimate;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\DrivesRecommendationRuns;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubSystemOneClient;

final class ScoringRecommendationEngineTest extends DbTestCase
{
    use BuildsTickContexts;
    use DrivesRecommendationRuns;
    use SeedsUsers;

    private const string PROFILE = 'Likes Rust and homelab.';

    private const int DISAGREEING_WINDOW_OVERRIDE = 131_072;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('jev-engine@example.test');
        $this->fixtures->seedReadyScoringSettings($this->owner);
    }

    /** A poll tick never waits: the 429 defers the run, halves its concurrency and strikes nothing. */
    public function testAPollWaveThatMeetsA429DefersWithoutAStrike(): void
    {
        $this->startRunAfterTheWarmUp(SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Poll);
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
        $this->startRunAfterTheWarmUp(3 * SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Poll);
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
        $this->startRunAfterTheWarmUp(SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Worker);
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
        $this->startRunAfterTheWarmUp(3 * SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Worker);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.7);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.6);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);

        $this->advancer()->advance($this->owner, TickDriver::Worker);

        self::assertSame(4, $this->activeRun()->getProgress()->batchesDone);
    }

    /** One failure in the middle of a wave banks nothing, yet every answered sibling still bills what it cost. */
    public function testAFailureAmidAWaveBanksNothingAndBillsEveryAnswer(): void
    {
        $this->startRunAfterTheWarmUp(3 * SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Worker);
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
        $this->startAndSnapshot(TickDriver::Poll);
        $run = $this->activeRun();
        $this->systemOne()->duringNextCall(function () use ($run): void {
            $run->cancel(new \DateTimeImmutable('2026-10-02 09:00:00'));
            $this->entityManager->flush();
        });
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.9);

        $this->advancer()->advance($this->owner);

        self::assertSame(0, $this->latestRun()->getProgress()->batchesDone);
    }

    public function testARefusedKeyStrikesTheRunAndSettlesItsRow(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->startAndSnapshot(TickDriver::Poll);
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
        $this->startAndSnapshot(TickDriver::Poll);
        $this->sealKeyForAnotherAccount();

        try {
            $this->advancer()->advance($this->owner);
            self::fail('An unreadable key must propagate.');
        } catch (AiKeyUnreadableException) {
        }

        self::assertSame(CallVerdict::TransportFailed, $this->lastLog()->getVerdict());
        self::assertSame('The stored API key cannot be opened.', $this->lastLog()->getErrorDetail());
    }

    /** A refused request would be refused again, so the first refusal fails the run with what the provider said. */
    public function testARejectedRequestFailsTheRunAtOnceWithTheProvidersDetail(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->startAndSnapshot(TickDriver::Poll);
        $this->systemOne()->queueFailure(new ProviderRejectedRequestException(
            400,
            'That provider refused the request (status 400): Model typesafe/jev-preview does not exist',
        ));

        $report = $this->advancer()->advance($this->owner);

        $run = $this->latestRun();
        self::assertSame('failed', $report->status);
        self::assertSame('failed', $run->getStatus()->value);
        self::assertSame(
            \sprintf(
                'The AI provider at %s failed: That provider refused the request (status 400): '
                . 'Model typesafe/jev-preview does not exist',
                $this->owner->getActiveAiProviderSettings()?->getBaseUrl(),
            ),
            $run->getError(),
        );
        self::assertSame(0, $run->getTransportFailures());
        self::assertSame(CallVerdict::TransportFailed, $this->lastLog()->getVerdict());
    }

    /** A reply missing a candidate's Noul is retried in the tick; after MAX_ATTEMPTS the batch yields no winners. */
    public function testAnUnusableReplyIsRetriedThenTheBatchYieldsNothing(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->startAndSnapshot(TickDriver::Poll);
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
            array_map(
                static fn (RecommendationRunLog $log): ?CallVerdict => $log->getVerdict(),
                $this->logs($run),
            ),
        );
    }

    public function testAnLlmRunWhoseConnectionSwitchedToJevFailsWithoutAnyCall(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->fixtures->storeProfile($this->owner, self::PROFILE);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->chooseModel(new ModelDescriptor('gpt-4o', 128_000), new \DateTimeImmutable('2026-10-02 09:00:00'));
        $this->entityManager->flush();
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->chooseModel(
            new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-02 09:10:00'),
        );
        $this->entityManager->flush();

        $this->advancer()->advance($this->owner);

        $run = $this->latestRun();
        self::assertSame('failed', $run->getStatus()->value);
        self::assertSame(TickPhases::ENGINE_SWITCH, $run->getError());
        self::assertSame([], $this->systemOne()->requests());
    }

    /** No history, so no profile: the account's to fix, so the run fails with the reason and never strikes. */
    public function testARunWithoutAProfileFailsWithTheReasonAndNeverAsksSystemOne(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $profileConnection = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'qwen3-14b');
        $this->fixtures->chooseProfileConnection($this->owner, $profileConnection);
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);         // waits for the profile run it starts
        $this->tickTheProfileRun($this->owner);           // no history: it completes without a profile
        $this->advancer()->advance($this->owner);         // the snapshot freezes no profile

        $report = $this->advancer()->advance($this->owner);

        $run = $this->latestRun();
        self::assertSame('failed', $report->status);
        self::assertSame(ScoringRecommendationEngine::NO_PROFILE, $run->getError());
        self::assertSame(0, $run->getTransportFailures());
        self::assertSame([], $this->logs($run));
        self::assertSame([], $this->systemOne()->requests());
    }

    /** The profile is the run's frozen copy: a later wave sends what this run froze, not the settings' copy. */
    public function testEveryWaveSendsTheProfileThisRunFroze(): void
    {
        $this->startRunAfterTheWarmUp(SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Poll);
        $this->settingsWriter()->storeProfile(
            $this->owner,
            new StoredProfile('Rewritten elsewhere.', null, null, null),
        );
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.4);

        $this->advancer()->advance($this->owner, TickDriver::Poll);

        $requests = $this->systemOne()->requests();
        $lastRequest = end($requests);
        self::assertNotFalse($lastRequest);
        self::assertSame(['profile' => self::PROFILE], $lastRequest->state);
    }

    /** Favorites go beside the profile, newest first, in the shape the candidates take. */
    public function testEveryWaveSendsTheReadersFavorites(): void
    {
        $this->startRunAfterTheWarmUp(SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Poll);
        $favorites = $this->fixtures->seedFavorites($this->owner, 'liked', 2);
        usort($favorites, static fn (Entry $left, Entry $right): int
            => $right->getEffectiveDate() <=> $left->getEffectiveDate());
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.4);

        $this->advancer()->advance($this->owner, TickDriver::Poll);

        $requests = $this->systemOne()->requests();
        $lastRequest = end($requests);
        self::assertNotFalse($lastRequest);
        self::assertIsArray($lastRequest->state['favorites']);
        self::assertSame(
            array_map(static fn (Entry $entry): string => $entry->getTitle(), $favorites),
            array_column($lastRequest->state['favorites'], 'title'),
        );
    }

    /** Kev's 8k window gives the reader 2,457 tokens: a longer profile is cut to fit, whatever the account overrides. */
    public function testTheStateIsFittedToTheConnectionsWindow(): void
    {
        $this->fixtures->contextWindowSettings($this->owner, self::DISAGREEING_WINDOW_OVERRIDE);
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->chooseModel(
            new ModelDescriptor('jaredpalmer/kev-4b', 8_192, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );
        $this->entityManager->flush();
        $this->fixtures->storeProfile($this->owner, str_repeat('Likes Rust and homelab. ', 1_000));
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);   // the snapshot
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);

        $this->advancer()->advance($this->owner);

        $requests = $this->systemOne()->requests();
        self::assertCount(1, $requests);
        $stateTokens = TokenEstimate::of(CompactJson::encode($requests[0]->state));
        self::assertLessThanOrEqual(2_457, $stateTokens);
        self::assertGreaterThan(2_000, $stateTokens);
    }

    /** 731-token questions (SystemOneProtocolTest): Kev's 8k window takes five a request, Jev's 32k all twelve. */
    public function testEachRequestIsBoundedByTheConnectionsWindow(): void
    {
        $this->fixtures->contextWindowSettings($this->owner, self::DISAGREEING_WINDOW_OVERRIDE);

        self::assertSame([5, 5, 2], $this->requestSizes(8_192));
        self::assertSame([12], $this->requestSizes(32_000));
    }

    /** A gateway's invalid byte: the reply is unusable, and the run log still holds valid UTF-8 (MySQL strict). */
    public function testAnInvalidByteInAReplyNeverReachesTheRunLog(): void
    {
        $this->startRunAfterTheWarmUp(SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, TickDriver::Poll);
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->systemOne()->queueBody("{\"model\":\"jev-1.13.0\",\"answers\":{},\"note\":\"\xC3\"}");
        }

        $this->advancer()->advance($this->owner, TickDriver::Poll);

        foreach ($this->logs($this->latestRun()) as $log) {
            self::assertTrue(mb_check_encoding($log->getResponseText(), 'UTF-8'), 'A log row holds invalid UTF-8.');
        }
    }

    /** @return list<int> */
    private function requestSizes(int $contextWindowTokens): array
    {
        $now = new \DateTimeImmutable('2026-10-05 09:00:00');
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->chooseModel(
            new ModelDescriptor('acme/decider-2', $contextWindowTokens, ScoringProtocol::SystemOne),
            $now,
        );
        $tick = $this->tick(new RecommendationRun($this->owner, $now));
        $engines = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $engines);

        $batches = $engines->engineOf($tick->engineKind)->packBatches(self::heavyCandidates(12), $tick);

        return array_map(\count(...), $batches);
    }

    /** @return list<ArticleLineModel> entry ids 1…$count, three-byte characters in every field */
    private static function heavyCandidates(int $count): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel => new ArticleLineModel(
                $entryId,
                str_repeat('漢', 300),
                'Feed',
                '2026-10-01',
                str_repeat('漢', 600),
            ),
            range(1, $count),
        );
    }

    /** Snapshot, then the one-request warm-up wave banks the first full batch. */
    private function startRunAfterTheWarmUp(int $candidateCount, TickDriver $driver): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, $candidateCount);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->setBatchConcurrency(4);
        $this->entityManager->flush();
        $this->startAndSnapshot($driver);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.4);
        $this->advancer()->advance($this->owner, $driver);
        self::assertSame(1, $this->activeRun()->getProgress()->batchesDone);
    }

    private function startAndSnapshot(TickDriver $driver): void
    {
        $this->fixtures->storeProfile($this->owner, self::PROFILE);
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner, $driver);   // the snapshot
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
        $connection->chooseModel(new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne), $now);
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
}
