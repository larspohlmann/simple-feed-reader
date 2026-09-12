<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Profiling\ExcimerSampler;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('excimer')]
final class ExcimerSamplerTest extends TestCase
{
    public function testCollectsCollapsedStacksWhileRunning(): void
    {
        $sampler = new ExcimerSampler();
        $sampler->start(0.001);
        self::assertTrue($sampler->isRunning());

        $spin = 0;
        $until = microtime(true) + 0.05;
        while (microtime(true) < $until) {
            ++$spin;
        }
        $profile = $sampler->stop();

        self::assertNotNull($profile);
        self::assertGreaterThan(0, $profile->sampleCount);
        self::assertSame(1000, $profile->sampleRateHz);
        self::assertStringContainsString(';', $profile->collapsedStacks);
        self::assertFalse($sampler->isRunning());
    }

    public function testStopWithoutStartIsNull(): void
    {
        self::assertNull((new ExcimerSampler())->stop());
    }
}
