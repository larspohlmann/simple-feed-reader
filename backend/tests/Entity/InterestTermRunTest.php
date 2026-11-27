<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Entity\InterestTermRun;
use App\Entity\User;
use App\Enum\RunStatus;
use PHPUnit\Framework\TestCase;

final class InterestTermRunTest extends TestCase
{
    private const string PROFILE = '2026-10-03 06:00:00';

    public function testANewRunIsRunningForItsProfile(): void
    {
        $run = $this->runningRun();

        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertTrue($run->isForProfile(new \DateTimeImmutable(self::PROFILE)));
        self::assertFalse($run->isForProfile(new \DateTimeImmutable('2026-10-03 06:00:01')));
        self::assertSame('llm.example.test', $run->getProviderHost());
        self::assertSame('qwen3-14b', $run->getModel());
    }

    public function testCompleteStampsTheTimeAndClearsTheCallAttempts(): void
    {
        $run = $this->runningRun();
        $run->recordInvalidReply('not json');
        $run->recordTransportFailure();

        $run->complete(new \DateTimeImmutable('2026-10-03 07:00:00'));

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertSame('2026-10-03 07:00:00', $run->getCompletedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(0, $run->getAttempts());
        self::assertSame(0, $run->getTransportFailures());
        self::assertNull($run->getLastInvalidReply());
    }

    public function testFailKeepsTheErrorAndTheCallAttempts(): void
    {
        $run = $this->runningRun();
        $run->recordInvalidReply('not json');

        $run->fail('gave up', new \DateTimeImmutable('2026-10-03 07:00:00'));

        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame('gave up', $run->getError());
        self::assertSame('2026-10-03 07:00:00', $run->getCompletedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(1, $run->getAttempts());
        self::assertSame('not json', $run->getLastInvalidReply());
    }

    public function testAttemptsExhaustAtTheLimit(): void
    {
        $run = $this->runningRun();

        foreach (range(1, InterestTermRun::MAX_ATTEMPTS - 1) as $ignored) {
            $run->recordInvalidReply('nope');
        }
        self::assertFalse($run->hasExhaustedAttempts());

        $run->recordInvalidReply('nope');
        self::assertTrue($run->hasExhaustedAttempts());
    }

    public function testTransportFailuresExhaustAtTheLimit(): void
    {
        $run = $this->runningRun();

        foreach (range(1, InterestTermRun::MAX_TRANSPORT_FAILURES - 1) as $ignored) {
            $run->recordTransportFailure();
        }
        self::assertFalse($run->hasExhaustedTransportRetries());

        $run->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    public function testAFinishedRunTakesNoFurtherTransition(): void
    {
        foreach (
            [
            static fn (InterestTermRun $run) => $run->complete(new \DateTimeImmutable()),
            static fn (InterestTermRun $run) => $run->fail('late', new \DateTimeImmutable()),
            static fn (InterestTermRun $run) => $run->recordInvalidReply('late'),
            static fn (InterestTermRun $run) => $run->recordTransportFailure(),
            ] as $transition
        ) {
            $run = $this->runningRun();
            $run->complete(new \DateTimeImmutable('2026-10-03 07:00:00'));

            try {
                $transition($run);
                self::fail('A completed run accepted a transition.');
            } catch (InvalidRunStatusException $exception) {
                self::assertStringContainsString('"completed"', $exception->getMessage());
            }
        }
    }

    private function runningRun(): InterestTermRun
    {
        return new InterestTermRun(
            new User('run@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z')),
            new \DateTimeImmutable(self::PROFILE),
            new \DateTimeImmutable('2026-10-03 06:30:00'),
            'llm.example.test',
            'qwen3-14b',
        );
    }
}
