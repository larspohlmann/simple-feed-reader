<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ScoringFamily;
use App\Enum\ScoringProtocol;
use PHPUnit\Framework\TestCase;

final class ScoringProtocolTest extends TestCase
{
    public function testASystemOneModelIsADecisionModel(): void
    {
        self::assertSame(ScoringFamily::Decision, ScoringProtocol::SystemOne->family());
    }
}
