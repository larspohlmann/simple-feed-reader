<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\ResponseContextModel;
use App\Service\Social\Model\ResponseKind;
use PHPUnit\Framework\TestCase;

final class ResponseContextModelTest extends TestCase
{
    public function testEachKindIsAllowedByItsOwnGate(): void
    {
        $repliesOnly = new ResponseContextModel(canReply: true, canQuote: false);
        $quotesOnly = new ResponseContextModel(canReply: false, canQuote: true);

        self::assertTrue($repliesOnly->allows(ResponseKind::Reply));
        self::assertFalse($repliesOnly->allows(ResponseKind::Quote));
        self::assertFalse($quotesOnly->allows(ResponseKind::Reply));
        self::assertTrue($quotesOnly->allows(ResponseKind::Quote));
    }
}
