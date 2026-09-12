<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Profiling\NullProfileSampler;
use PHPUnit\Framework\TestCase;

final class NullProfileSamplerTest extends TestCase
{
    public function testIsInertEndToEnd(): void
    {
        $sampler = new NullProfileSampler();
        $sampler->start(0.001);

        self::assertFalse($sampler->isRunning());
        self::assertNull($sampler->stop());
        self::assertFalse($sampler->isAvailable());
    }
}
