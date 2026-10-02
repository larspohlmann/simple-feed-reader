<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Enum\CallVerdict;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\BatchWaveRounds;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\RecommendationCallRecorder;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\ScriptedBatchOutcome;
use App\Tests\Support\ScriptedBatchWave;
use App\Tests\Support\ScriptedBatchWaveEngine;
use App\Tests\Support\SeedsUsers;

final class BatchWaveRoundsTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private RecommendationRun $run;
    private ScriptedBatchWaveEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $owner = $this->user('batch-wave-rounds@example.test');
        $fixtures->seedReadyAiSettings($owner);
        $this->run = $fixtures->createRun($owner);
        $this->entityManager->flush();

        /** @var RecommendationCallRecorder $callRecorder */
        $callRecorder = self::getContainer()->get(RecommendationCallRecorder::class);
        $this->engine = new ScriptedBatchWaveEngine($callRecorder);
    }

    public function testEveryUsableBatchWinsInPlanOrderAndSettlesItsRowUsable(): void
    {
        $this->engine->queueRound(self::completed('usable 0', 'usable 1'));

        $result = $this->resolve(self::batch(0, 10), self::batch(1, 20));

        self::assertSame([[self::winner(10)], [self::winner(20)]], $result->winners);
        self::assertFalse($result->rateLimitObserved);
        self::assertSame([[0, 1]], $this->engine->sentRounds());
        self::assertSame([CallVerdict::Usable, CallVerdict::Usable], $this->verdicts());
        self::assertSame(['usable 0', 'usable 1'], $this->responseTexts());
    }

    public function testAPrunedBatchYieldsNoWinnersWithoutACall(): void
    {
        $this->engine->queueRound(self::completed('usable 1'));

        $result = $this->resolve(new WaveBatchModel(0, [10], []), self::batch(1, 20));

        self::assertSame([[], [self::winner(20)]], $result->winners);
        self::assertSame([[1]], $this->engine->sentRounds());
    }

    public function testAnUnusableBatchRetriesAloneUntilItAnswers(): void
    {
        $this->engine->queueRound(self::completed('usable 0', 'garbled'));
        $this->engine->queueRound(self::completed('usable 1'));

        $result = $this->resolve(self::batch(0, 10), self::batch(1, 20));

        self::assertSame([[self::winner(10)], [self::winner(20)]], $result->winners);
        self::assertSame([[0, 1], [1]], $this->engine->sentRounds());
        self::assertSame([CallVerdict::Usable, CallVerdict::Unusable, CallVerdict::Usable], $this->verdicts());
        self::assertSame(['usable 0', 'garbled', 'usable 1'], $this->responseTexts());
    }

    public function testABatchStillUnusableAfterItsAttemptsYieldsNoWinners(): void
    {
        for ($attempt = 1; $attempt <= RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->engine->queueRound(self::completed('garbled'));
        }

        $result = $this->resolve(self::batch(0, 10));

        self::assertSame([[]], $result->winners);
        self::assertCount(RecommendationRun::MAX_ATTEMPTS, $this->engine->sentRounds());
        self::assertSame(array_fill(0, RecommendationRun::MAX_ATTEMPTS, CallVerdict::Unusable), $this->verdicts());
    }

    public function testARateLimitSeenInAnEarlierRoundIsReported(): void
    {
        $this->engine->queueRound(RateLimitedResultModel::completed([ScriptedBatchOutcome::reply('garbled')], true));
        $this->engine->queueRound(self::completed('usable 0'));

        self::assertTrue($this->resolve(self::batch(0, 10))->rateLimitObserved);
    }

    public function testADeferralSettlesEveryCallAndThrowsItsWait(): void
    {
        $this->engine->queueRound(RateLimitedResultModel::deferred(20.0));

        try {
            $this->resolve(self::batch(0, 10), self::batch(1, 20));
            self::fail('A deferral must throw.');
        } catch (ProviderRateLimitedException $exception) {
            self::assertSame(20.0, $exception->waitSeconds());
        }

        self::assertSame(array_fill(0, 2, CallVerdict::TransportFailed), $this->verdicts());
        self::assertSame(array_fill(0, 2, 'Provider rate limited; deferring.'), $this->errorDetails());
    }

    public function testASendThatThrowsSettlesEveryCallWithItsMessageAndPropagatesIt(): void
    {
        $unreadable = new \RuntimeException('The stored API key cannot be opened.');
        $this->engine->queueRound($unreadable);

        try {
            $this->resolve(self::batch(0, 10), self::batch(1, 20));
            self::fail('The send error must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($unreadable, $exception);
        }

        self::assertSame(array_fill(0, 2, CallVerdict::TransportFailed), $this->verdicts());
        self::assertSame(array_fill(0, 2, 'The stored API key cannot be opened.'), $this->errorDetails());
    }

    /** A bystander borrows the wave's failure; a spoiled reply names its own cause. */
    public function testOneEndpointFailureSettlesTheWholeRoundAndBanksNothing(): void
    {
        $down = new ProviderUnreachableException('That provider is down.');
        $this->engine->queueRound(RateLimitedResultModel::completed([
            ScriptedBatchOutcome::reply('usable 0'),
            ScriptedBatchOutcome::spoiled('usable 1', new \RuntimeException('The reply was cut short.')),
            ScriptedBatchOutcome::failure($down),
        ], false));

        try {
            $this->resolve(self::batch(0, 10), self::batch(1, 20), self::batch(2, 30));
            self::fail('The wave failure must propagate.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame($down, $exception);
        }

        self::assertSame(array_fill(0, 3, CallVerdict::TransportFailed), $this->verdicts());
        self::assertSame(
            ['That provider is down.', 'The reply was cut short.', 'That provider is down.'],
            $this->errorDetails(),
        );
    }

    /** A spoiled reply is no endpoint failure: it is judged like any reply, and retried when unusable. */
    public function testASpoiledReplyIsJudgedNotAborted(): void
    {
        $this->engine->queueRound(RateLimitedResultModel::completed([
            ScriptedBatchOutcome::spoiled('cut', new \RuntimeException('The reply was cut short.')),
        ], false));
        $this->engine->queueRound(self::completed('usable 0'));

        $result = $this->resolve(self::batch(0, 10));

        self::assertSame([[self::winner(10)]], $result->winners);
        self::assertSame([CallVerdict::Unusable, CallVerdict::Usable], $this->verdicts());
    }

    public function testARunCancelledDuringTheRoundStopsAfterItsRowsSettle(): void
    {
        $this->run->cancel(new \DateTimeImmutable('2026-08-08T10:05:00Z'));
        $this->entityManager->flush();
        $this->engine->queueRound(self::completed('garbled'));

        try {
            $this->resolve(self::batch(0, 10));
            self::fail('A cancelled run must stop the wave.');
        } catch (RecommendationRunCancelledException) {
        }

        self::assertCount(1, $this->engine->sentRounds());
        self::assertSame([CallVerdict::Unusable], $this->verdicts());
    }

    private function resolve(WaveBatchModel ...$batches): BatchWaveResultModel
    {
        $rounds = self::getContainer()->get(BatchWaveRounds::class);
        self::assertInstanceOf(BatchWaveRounds::class, $rounds);

        return $rounds->resolve($this->engine, new ScriptedBatchWave($this->tick($this->run), array_values($batches)));
    }

    /** @return RateLimitedResultModel<ScriptedBatchOutcome> */
    private static function completed(string ...$replies): RateLimitedResultModel
    {
        return RateLimitedResultModel::completed(
            array_values(array_map(ScriptedBatchOutcome::reply(...), $replies)),
            false,
        );
    }

    private static function batch(int $index, int $entryId): WaveBatchModel
    {
        $line = new ArticleLineModel($entryId, 'Title', 'Feed', '2026-08-08', null);

        return new WaveBatchModel($index, [$entryId], [$entryId => $line]);
    }

    /** @return array{id: int, score: int, reason: string} */
    private static function winner(int $entryId): array
    {
        return ['id' => $entryId, 'score' => ScriptedBatchWaveEngine::SCORE, 'reason' => ''];
    }

    /** @return list<?CallVerdict> */
    private function verdicts(): array
    {
        return array_map(static fn (RecommendationRunLog $log): ?CallVerdict => $log->getVerdict(), $this->logs());
    }

    /** @return list<string> */
    private function responseTexts(): array
    {
        return array_map(static fn (RecommendationRunLog $log): string => $log->getResponseText(), $this->logs());
    }

    /** @return list<?string> */
    private function errorDetails(): array
    {
        return array_map(static fn (RecommendationRunLog $log): ?string => $log->getErrorDetail(), $this->logs());
    }

    /** @return list<RecommendationRunLog> in the order the calls were opened */
    private function logs(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(RecommendationRunLog::class)->findBy(
            ['run' => $this->run->requireId()],
            ['id' => 'ASC'],
        );
    }
}
