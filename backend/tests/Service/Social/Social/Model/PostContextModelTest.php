<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Model;

use App\Service\Social\Social\Model\PostContextModel;
use App\Service\Social\Social\Model\ResponseKind;
use PHPUnit\Framework\TestCase;

final class PostContextModelTest extends TestCase
{
    public function testEachKindIsAllowedByItsOwnGate(): void
    {
        $repliesOnly = new PostContextModel(canReply: true, canQuote: false);
        $quotesOnly = new PostContextModel(canReply: false, canQuote: true);

        self::assertTrue($repliesOnly->allows(ResponseKind::Reply));
        self::assertFalse($repliesOnly->allows(ResponseKind::Quote));
        self::assertFalse($quotesOnly->allows(ResponseKind::Reply));
        self::assertTrue($quotesOnly->allows(ResponseKind::Quote));
    }
}
