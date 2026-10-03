<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use PHPUnit\Framework\TestCase;

final class ProfileRunTest extends TestCase
{
    public function testANewRunIsPendingWithItsTriggerAndNoFingerprint(): void
    {
        $profileRun = $this->profileRun();

        self::assertSame(RunStatus::Pending, $profileRun->getStatus());
        self::assertSame(ProfileRunTrigger::Scheduled, $profileRun->getTrigger());
        self::assertSame('2026-10-03 09:00:00', $profileRun->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($profileRun->getFingerprint());
        self::assertNull($profileRun->getOutcome());
    }

    public function testStartRecordsTheFingerprintAndTheModelItCalls(): void
    {
        $profileRun = $this->profileRun();

        $profileRun->start('fingerprint-7', 'llm.example.test', 'qwen3-14b');

        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame('fingerprint-7', $profileRun->getFingerprint());
        self::assertSame('llm.example.test', $profileRun->getProviderHost());
        self::assertSame('qwen3-14b', $profileRun->getModel());
    }

    public function testARunningRunCannotStartAgain(): void
    {
        $profileRun = $this->runningProfileRun();

        $this->expectException(InvalidRunStatusException::class);
        $profileRun->start('fingerprint-8', 'llm.example.test', 'qwen3-14b');
    }

    public function testCompleteRecordsTheOutcomeAndTheTime(): void
    {
        $profileRun = $this->runningProfileRun();

        $profileRun->complete(ProfileRunOutcome::Unchanged, new \DateTimeImmutable('2026-10-03 09:04:00'));

        self::assertSame(RunStatus::Completed, $profileRun->getStatus());
        self::assertSame(ProfileRunOutcome::Unchanged, $profileRun->getOutcome());
        self::assertSame('2026-10-03 09:04:00', $profileRun->getCompletedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAPendingRunCannotComplete(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->profileRun()->complete(ProfileRunOutcome::Generated, new \DateTimeImmutable('2026-10-03 09:04:00'));
    }

    public function testTheThirdUnusableReplyExhaustsTheAttempts(): void
    {
        $profileRun = $this->runningProfileRun();
        $profileRun->recordInvalidReply('not json');
        $profileRun->recordInvalidReply('still not json');

        self::assertFalse($profileRun->hasExhaustedAttempts());

        $profileRun->recordInvalidReply('{"profil":"typo"}');

        self::assertTrue($profileRun->hasExhaustedAttempts());
        self::assertSame(3, $profileRun->getAttempts());
        self::assertSame('{"profil":"typo"}', $profileRun->getLastInvalidReply());
    }

    public function testTheThirdTransportFailureExhaustsTheRetries(): void
    {
        $profileRun = $this->runningProfileRun();
        $profileRun->recordTransportFailure();
        $profileRun->recordTransportFailure();

        self::assertFalse($profileRun->hasExhaustedTransportRetries());

        $profileRun->recordTransportFailure();

        self::assertTrue($profileRun->hasExhaustedTransportRetries());
        self::assertSame(3, $profileRun->getTransportFailures());
    }

    public function testAPendingRunCannotRecordAnUnusableReply(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->profileRun()->recordInvalidReply('not json');
    }

    public function testFailIsLegalFromPendingAndRecordsTheError(): void
    {
        $profileRun = $this->profileRun();

        $profileRun->fail('No connection can build your profile.', new \DateTimeImmutable('2026-10-03 09:01:00'));

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame('No connection can build your profile.', $profileRun->getError());
        self::assertSame('2026-10-03 09:01:00', $profileRun->getCompletedAt()?->format('Y-m-d H:i:s'));
    }

    public function testACompletedRunCannotFail(): void
    {
        $profileRun = $this->runningProfileRun();
        $profileRun->complete(ProfileRunOutcome::Generated, new \DateTimeImmutable('2026-10-03 09:04:00'));

        $this->expectException(InvalidRunStatusException::class);
        $profileRun->fail('late', new \DateTimeImmutable('2026-10-03 09:05:00'));
    }

    private function profileRun(): ProfileRun
    {
        return new ProfileRun(
            new User('profile-run@example.test', new \DateTimeImmutable('2026-10-03 08:00:00')),
            ProfileRunTrigger::Scheduled,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
    }

    private function runningProfileRun(): ProfileRun
    {
        $profileRun = $this->profileRun();
        $profileRun->start('fingerprint-7', 'llm.example.test', 'qwen3-14b');

        return $profileRun;
    }
}
