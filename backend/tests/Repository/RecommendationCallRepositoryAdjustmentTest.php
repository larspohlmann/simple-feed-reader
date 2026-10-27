<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RequestAdjustment;
use App\Repository\CallingRun;
use App\Repository\CallSettlement;
use App\Repository\RecommendationCallRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\Model\RequestAdjustmentModel;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationCallRepositoryAdjustmentTest extends DbTestCase
{
    private const string REJECTION = 'That provider refused the request (status 400): max_tokens is too large.';
    private const string TIMEOUT = 'The provider did not answer in time.';

    private User $user;
    private RecommendationCallRepository $calls;
    private RecommendationRunLogRepository $logs;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('adjustment-owner@example.test');
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        $this->logs = $logs;
        $this->calls = new RecommendationCallRepository($this->entityManager->getConnection());
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testTheMarkLandsOnTheRejectedCallAndNeverOnANewerTimeout(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $rejected = $this->settledFailure($run, 1, self::REJECTION);
        $answered = $this->answered($run, 2);
        $timedOut = $this->settledFailure($run, 3, self::TIMEOUT);

        $this->calls->recordAdjustment(
            CallingRun::recommendationRun($run->requireId()),
            RequestAdjustmentModel::outputCap(32_768),
            self::REJECTION,
        );

        $adjusted = $this->adjustmentsByLog($run);
        self::assertSame([RequestAdjustment::OutputCap, '32768'], $adjusted[$rejected->requireId()]);
        self::assertSame([null, null], $adjusted[$answered->requireId()]);
        self::assertSame([null, null], $adjusted[$timedOut->requireId()]);
    }

    public function testOnlyTheNewestOfTwoRowsCarryingTheRejectionIsMarked(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $older = $this->settledFailure($run, 1, self::REJECTION);
        $newer = $this->settledFailure($run, 2, self::REJECTION);

        $this->calls->recordAdjustment(
            CallingRun::recommendationRun($run->requireId()),
            RequestAdjustmentModel::outputCap(32_768),
            self::REJECTION,
        );

        $adjusted = $this->adjustmentsByLog($run);
        self::assertSame([null, null], $adjusted[$older->requireId()]);
        self::assertSame([RequestAdjustment::OutputCap, '32768'], $adjusted[$newer->requireId()]);
    }

    public function testAnotherRunsRejectionAndANonTransportVerdictAreNeverMarked(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $rejected = $this->settledFailure($run, 1, self::REJECTION);
        $unusable = $this->settledFailure($run, 2, self::REJECTION, CallVerdict::Unusable);
        $otherRun = $this->fixtures->createRun($this->user);
        $otherRunRejected = $this->settledFailure($otherRun, 3, self::REJECTION);

        $this->calls->recordAdjustment(
            CallingRun::recommendationRun($run->requireId()),
            RequestAdjustmentModel::outputCap(32_768),
            self::REJECTION,
        );

        $adjusted = $this->adjustmentsByLog($run) + $this->adjustmentsByLog($otherRun);
        self::assertSame([RequestAdjustment::OutputCap, '32768'], $adjusted[$rejected->requireId()]);
        self::assertSame([null, null], $adjusted[$unusable->requireId()]);
        self::assertSame([null, null], $adjusted[$otherRunRejected->requireId()]);
    }

    private function settledFailure(
        RecommendationRun $run,
        int $attempt,
        string $errorDetail,
        CallVerdict $verdict = CallVerdict::TransportFailed,
    ): RecommendationRunLog {
        $log = $this->newLog($run, $attempt);
        $this->calls->settleTransportFailure(
            new CallSettlement($log->requireId(), $this->outcome($verdict)),
            $errorDetail,
        );

        return $log;
    }

    private function answered(RecommendationRun $run, int $attempt): RecommendationRunLog
    {
        $log = $this->newLog($run, $attempt);
        $this->calls->settleAnswered(
            new CallSettlement($log->requireId(), $this->outcome(CallVerdict::Usable)),
            'answer',
        );

        return $log;
    }

    private function newLog(RecommendationRun $run, int $attempt): RecommendationRunLog
    {
        $log = $this->fixtures->log(
            $run,
            CallPhase::Batch,
            1,
            $attempt,
            'request',
            new \DateTimeImmutable('2026-08-08T10:00:00Z')->modify(\sprintf('+%d minutes', $attempt)),
        );
        $this->entityManager->flush();

        return $log;
    }

    private function outcome(CallVerdict $verdict): CallOutcome
    {
        return new CallOutcome($verdict, 10, new \DateTimeImmutable('2026-08-08T11:00:00Z'), null);
    }

    /** @return array<int, array{0: ?RequestAdjustment, 1: ?string}> */
    private function adjustmentsByLog(RecommendationRun $run): array
    {
        $this->entityManager->clear();
        $adjusted = [];
        foreach ($this->logs->listForRun($this->user, $run->requireId()) as $row) {
            $adjusted[$row['id']] = [$row['adjustedSetting'], $row['adjustedValue']];
        }

        return $adjusted;
    }
}
