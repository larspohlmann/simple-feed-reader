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

    public function testARerankModelIsAReranker(): void
    {
        self::assertSame(ScoringFamily::Reranker, ScoringProtocol::Rerank->family());
        self::assertSame('rerank', ScoringProtocol::Rerank->value);
        self::assertSame('reranker', ScoringFamily::Reranker->value);
    }
}
