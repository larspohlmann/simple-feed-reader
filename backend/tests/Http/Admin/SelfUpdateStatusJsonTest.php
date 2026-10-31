<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Http\Admin\SelfUpdateStatusJson;
use App\Service\SelfUpdate\Model\SelfUpdatePhase;
use App\Service\SelfUpdate\Model\SelfUpdateStatusModel;
use App\Service\SelfUpdate\Model\UpdateRunModel;
use PHPUnit\Framework\TestCase;

final class SelfUpdateStatusJsonTest extends TestCase
{
    public function testARunningRunHasAStartButNoFinishOrExitCode(): void
    {
        $run = new UpdateRunModel(
            'abc',
            SelfUpdatePhase::Running,
            new \DateTimeImmutable('2026-10-31T10:00:00+00:00'),
            null,
            null,
        );

        self::assertSame(
            [
                'updaterPresent' => true,
                'phase' => 'running',
                'startedAt' => '2026-10-31T10:00:00+00:00',
                'finishedAt' => null,
                'exitCode' => null,
                'log' => 'pulling images',
            ],
            SelfUpdateStatusJson::of(new SelfUpdateStatusModel(true, SelfUpdatePhase::Running, $run, 'pulling images')),
        );
    }

    public function testARunWithoutAStartTimeSendsNullForIt(): void
    {
        $run = new UpdateRunModel(
            'abc',
            SelfUpdatePhase::Failed,
            null,
            new \DateTimeImmutable('2026-10-31T10:05:00+00:00'),
            2,
        );

        $json = SelfUpdateStatusJson::of(new SelfUpdateStatusModel(false, SelfUpdatePhase::Failed, $run, ''));

        self::assertNull($json['startedAt']);
        self::assertSame('2026-10-31T10:05:00+00:00', $json['finishedAt']);
        self::assertSame(2, $json['exitCode']);
    }
}
