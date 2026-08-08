<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\CompletionBodyDecoder;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationCallRecorder;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
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

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $this->user = $factory->create('recorder-owner@example.test');

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
            new CompletionBodyDecoder(),
            $this->clock,
        );
    }

    public function testBeginWithDebugOnPersistsTheRequestBodyImmediately(): void
    {
        $this->seedDebugSettings(true);

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
    }

    public function testBeginWithDebugOffWritesNoRow(): void
    {
        $this->seedDebugSettings(false);

        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        self::assertSame([], $this->logs()->listForUser($this->user));
    }

    public function testCheckpointsAreThrottledToTheInterval(): void
    {
        $this->seedDebugSettings(true);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];

        $call->bodyGrew("data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n");
        self::assertSame(
            '',
            $this->freshLog($logId)->getResponseText(),
            'first growth inside the interval is not written',
        );

        $this->clock->modify('+3 seconds');
        $call->bodyGrew(
            "data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"llo\"}}]}\n",
        );

        self::assertSame('Hello', $this->freshLog($logId)->getResponseText());
    }

    public function testCheckpointUpdatesTheLivenessCounterEvenWithDebugOff(): void
    {
        $this->seedDebugSettings(false);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        $this->clock->modify('+3 seconds');
        $body = "data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n";
        $call->bodyGrew($body);

        $runId = $this->run->getId();
        self::assertNotNull($runId);
        $this->em->clear();
        $freshRun = $this->em->find(RecommendationRun::class, $runId);
        self::assertSame(\strlen($body), $freshRun?->getStreamedChars());
    }

    public function testFinishUsableStoresTextVerdictAndResetsLiveness(): void
    {
        $this->seedDebugSettings(true);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->bodyGrew('data: partial');

        $call->finishUsable('{"recommendations": []}');

        $log = $this->freshLog($logId);
        self::assertSame('{"recommendations": []}', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_USABLE, $log->getVerdict());
        $freshRun = $this->em->find(RecommendationRun::class, $this->run->getId());
        self::assertSame(0, $freshRun?->getStreamedChars());
    }

    public function testAbortKeepsThePartialTextWithTransportVerdict(): void
    {
        $this->seedDebugSettings(true);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->bodyGrew("data: {\"choices\":[{\"delta\":{\"content\":\"cut off\"}}]}\n");

        $call->abortAfterTransportFailure();

        $log = $this->freshLog($logId);
        self::assertSame('cut off', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_TRANSPORT_FAILED, $log->getVerdict());
    }

    public function testASecondBeginForTheSamePhaseCountsTheAttempt(): void
    {
        $this->seedDebugSettings(true);
        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm')->finishUnusable('bad');
        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        $rows = $this->logs()->listForUser($this->user);
        self::assertSame([1, 2], array_column($rows, 'attempt'));
    }

    private function seedDebugSettings(bool $enabled): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            debugEnabled: $enabled,
        ));
        $this->em->persist($settings);
        $this->em->flush();
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
