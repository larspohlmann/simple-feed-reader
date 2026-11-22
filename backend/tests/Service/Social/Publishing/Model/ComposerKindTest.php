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

    public function testALinkAndATextPostNeedNoPermission(): void
    {
        $closed = new PostContextModel(canReply: false, canQuote: false);

        self::assertTrue(ComposerKind::Link->isAllowedBy($closed));
        self::assertTrue(ComposerKind::Post->isAllowedBy($closed));
    }

    public function testOnlyAReplyAndAQuoteAnswerAPost(): void
    {
        self::assertTrue(ComposerKind::Reply->isResponse());
        self::assertTrue(ComposerKind::Quote->isResponse());
        self::assertFalse(ComposerKind::Link->isResponse());
        self::assertFalse(ComposerKind::Post->isResponse());
    }
}
