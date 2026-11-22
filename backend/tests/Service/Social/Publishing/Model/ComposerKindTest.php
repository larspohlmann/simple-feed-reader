<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Publishing\Model;

use App\Service\Social\Publishing\Model\ComposerKind;
use App\Service\Social\Social\Model\PostContextModel;
use PHPUnit\Framework\TestCase;

final class ComposerKindTest extends TestCase
{
    public function testAReplyNeedsTheAuthorsReplyPermission(): void
    {
        self::assertTrue(ComposerKind::Reply->isAllowedBy(new PostContextModel(canQuote: false)));
        self::assertFalse(ComposerKind::Reply->isAllowedBy(new PostContextModel(canReply: false)));
    }

    public function testAQuoteNeedsTheQuotePermission(): void
    {
        self::assertTrue(ComposerKind::Quote->isAllowedBy(new PostContextModel(canReply: false)));
        self::assertFalse(ComposerKind::Quote->isAllowedBy(new PostContextModel(canQuote: false)));
    }

    public function testALinkNeedsNoPermission(): void
    {
        self::assertTrue(ComposerKind::Link->isAllowedBy(new PostContextModel(canReply: false, canQuote: false)));
    }
}
