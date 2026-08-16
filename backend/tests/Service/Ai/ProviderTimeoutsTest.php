<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\ProviderTimeouts;
use PHPUnit\Framework\TestCase;

final class ProviderTimeoutsTest extends TestCase
{
    /**
     * The point of the type: the two profiles are different, and the slow one
     * is the more patient in both directions. A change that made them equal
     * would leave the setting in place while doing nothing.
     */
    public function testTheSlowProfileIsMorePatientThanTheStandardOneInBothBounds(): void
    {
        $standard = ProviderTimeouts::standard();
        $slow = ProviderTimeouts::forSlowModel();

        self::assertGreaterThan($standard->wallClockSeconds, $slow->wallClockSeconds);
        self::assertGreaterThan($standard->firstByteSeconds, $slow->firstByteSeconds);
    }

    /**
     * A first-byte bound at or above the wall clock could never fire: the call
     * would be killed by the wall clock first, and a dead connection would be
     * reported as an exhausted one. It also breaks WorkerPresence, which sizes
     * its freshness window against the first-byte bound precisely because that
     * bound is the smaller of the two.
     */
    public function testEveryProfileFailsSilenceBeforeItFailsTheWholeCall(): void
    {
        foreach ([ProviderTimeouts::standard(), ProviderTimeouts::forSlowModel()] as $timeouts) {
            self::assertLessThan($timeouts->wallClockSeconds, $timeouts->firstByteSeconds);
        }
    }

    /**
     * Both bounds stay finite. An unbounded call would hold the run's per-user
     * lock until the process died, and there would be no way to tell a hung
     * local server from a thinking one.
     */
    public function testEveryBoundIsFinite(): void
    {
        foreach ([ProviderTimeouts::standard(), ProviderTimeouts::forSlowModel()] as $timeouts) {
            self::assertGreaterThan(0.0, $timeouts->firstByteSeconds);
            self::assertFinite($timeouts->wallClockSeconds);
            self::assertFinite($timeouts->firstByteSeconds);
        }
    }
}
