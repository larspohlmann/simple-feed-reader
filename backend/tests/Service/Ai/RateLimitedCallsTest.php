<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\Model\RetryPlanModel;
use App\Service\Ai\RateLimitedCalls;
use App\Tests\Support\ScriptedRateLimitedOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RateLimitedCallsTest extends TestCase
{
    /**
     * Two of three calls are limited, asking 3 s and 7 s: a blocking plan waits the longer and re-sends only those two,
     * so the call that answered is not billed twice.
     */
    public function testABlockingPlanWaitsTheLongestHintAndResendsOnlyTheLimitedCalls(): void
    {
        $clock = new MockClock('2026-10-02 09:00:00');
        /** @var \ArrayObject<int, mixed> $sent */
        $sent = new \ArrayObject();
        /** @var \SplQueue<list<ScriptedRateLimitedOutcome>> $replies */
        $replies = new \SplQueue();
        $replies->enqueue([
            ScriptedRateLimitedOutcome::answered('first'),
            ScriptedRateLimitedOutcome::limited('second', 3),
            ScriptedRateLimitedOutcome::limited('third', 7),
        ]);
        $replies->enqueue([
            ScriptedRateLimitedOutcome::answered('second again'),
            ScriptedRateLimitedOutcome::answered('third again'),
        ]);
        $send = static function ($calls) use ($sent, $replies) {
            $sent[] = $calls;

            return $replies->dequeue();
        };

        $result = (new RateLimitedCalls($clock))->send(['a', 'b', 'c'], $send, RetryPlanModel::blocking());

        self::assertSame([['a', 'b', 'c'], ['b', 'c']], $sent->getArrayCopy());
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:07'), $clock->now());
        self::assertSame(
            ['first', 'second again', 'third again'],
            array_map(static fn (ScriptedRateLimitedOutcome $outcome): string => $outcome->label, $result->outcomes),
        );
        self::assertTrue($result->rateLimitObserved);
    }

    public function testADeferringPlanHandsTheLongestWaitBackWithoutResending(): void
    {
        $sends = 0;
        $send = static function ($calls) use (&$sends): array {
            ++$sends;

            return [ScriptedRateLimitedOutcome::limited('one', 4), ScriptedRateLimitedOutcome::limited('two', 11)];
        };

        $result = (new RateLimitedCalls(new MockClock()))->send(['a', 'b'], $send, RetryPlanModel::deferring());

        self::assertTrue($result->isDeferred());
        self::assertSame(11.0, $result->deferSeconds);
        self::assertSame(1, $sends);
    }
}
