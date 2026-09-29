<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Model;

use App\Service\Ai\Model\ProviderTimeoutsModel;
use PHPUnit\Framework\TestCase;

final class ProviderTimeoutsModelTest extends TestCase
{
    /**
     * The point of the type: the two profiles are different, and the slow one
     * is the more patient in both directions. A change that made them equal
     * would leave the setting in place while doing nothing.
     */
    public function testTheSlowProfileIsMorePatientThanTheStandardOneInBothBounds(): void
    {
        $standard = ProviderTimeoutsModel::standard();
        $slow = ProviderTimeoutsModel::forSlowModel();

        self::assertGreaterThan($standard->wallClockSeconds, $slow->wallClockSeconds);
        self::assertGreaterThan($standard->firstByteSeconds, $slow->firstByteSeconds);
    }

    /**
     * A first-byte bound at or above the wall clock never fires, reporting a dead connection as an exhausted one, and
     * WorkerPresence sizes its freshness window from the first-byte bound.
     */
    public function testEveryProfileFailsSilenceBeforeItFailsTheWholeCall(): void
    {
        foreach ([ProviderTimeoutsModel::standard(), ProviderTimeoutsModel::forSlowModel()] as $timeouts) {
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
        foreach ([ProviderTimeoutsModel::standard(), ProviderTimeoutsModel::forSlowModel()] as $timeouts) {
            self::assertGreaterThan(0.0, $timeouts->firstByteSeconds);
            self::assertFinite($timeouts->wallClockSeconds);
            self::assertFinite($timeouts->firstByteSeconds);
        }
    }
}
