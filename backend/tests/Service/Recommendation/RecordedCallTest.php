<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
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

    private function call(): RecordedCall
    {
        $runId = $this->run->getId();
        $logId = $this->log->getId();
        self::assertNotNull($runId);
        self::assertNotNull($logId);

        return new RecordedCall($this->em->getConnection(), $this->clock, $runId, $logId);
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
