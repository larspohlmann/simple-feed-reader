<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Fetch\Model\ResponseSizeLimit;
use PHPUnit\Framework\TestCase;

final class ResponseExchangeKindTest extends TestCase
{
    public function testAFeedAsksForAFeedFormatFirstAndIsCappedAtTheFeedLimit(): void
    {
        self::assertStringStartsWith('application/rss+xml, application/atom+xml', ResponseExchangeKind::Feed->accept());
        self::assertSame(ResponseSizeLimit::Feed, ResponseExchangeKind::Feed->sizeLimit());
    }

    public function testAnApiAsksForJsonAndIsCappedAtTheApiLimit(): void
    {
        self::assertSame('application/json', ResponseExchangeKind::Api->accept());
        self::assertSame(ResponseSizeLimit::Api, ResponseExchangeKind::Api->sizeLimit());
    }
}
