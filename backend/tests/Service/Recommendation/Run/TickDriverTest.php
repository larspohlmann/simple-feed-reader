<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Service\Recommendation\Run\TickDriver;
use PHPUnit\Framework\TestCase;

final class TickDriverTest extends TestCase
{
    public function testOnlyTheWorkerWaitsOutARateLimit(): void
    {
        self::assertTrue(TickDriver::Worker->retryPlan()->blocks());
        self::assertFalse(TickDriver::Poll->retryPlan()->blocks());
        self::assertFalse(TickDriver::Sweep->retryPlan()->blocks());
    }
}
