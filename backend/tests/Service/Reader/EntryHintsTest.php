<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EntryHints;
use App\Service\Reader\FeedMedia;
use PHPUnit\Framework\TestCase;

final class EntryHintsTest extends TestCase
{
    public function testNoHintsMeanNoTitleNoAuthorAndNoFeedMedia(): void
    {
        $hints = new EntryHints();

        self::assertNull($hints->title);
        self::assertNull($hints->author);
        self::assertNull($hints->feedMedia->posterFallback());
    }

    public function testKeepsTheFeedMediaItIsGiven(): void
    {
        $feedMedia = FeedMedia::none();

        self::assertSame($feedMedia, (new EntryHints(feedMedia: $feedMedia))->feedMedia);
    }
}
