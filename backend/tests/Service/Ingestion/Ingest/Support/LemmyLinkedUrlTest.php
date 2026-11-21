<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest\Support;

use App\Service\Ingestion\Ingest\Support\LemmyLinkedUrl;
use PHPUnit\Framework\TestCase;

final class LemmyLinkedUrlTest extends TestCase
{
    public function testRssBodyLeadsWithTheLink(): void
    {
        $body = '<a href="https://x.example/a" rel="noopener">https://x.example/a</a><blockquote><p>q</p></blockquote>';

        self::assertSame('https://x.example/a', LemmyLinkedUrl::parse($body));
    }

    public function testApiBodyLeadsWithTheLinkParagraph(): void
    {
        $body = '<p><a href="https://x.example/a">https://x.example/a</a></p><p>body</p>';

        self::assertSame('https://x.example/a', LemmyLinkedUrl::parse($body));
    }

    public function testTextPostHasNone(): void
    {
        self::assertNull(LemmyLinkedUrl::parse('<p>There’s a separate thread</p>'));
    }

    public function testAnchorWithOtherTextIsNotTheLink(): void
    {
        self::assertNull(LemmyLinkedUrl::parse('<p><a href="https://x.example/a">read this</a></p>'));
    }

    public function testNullAndEmptyHaveNone(): void
    {
        self::assertNull(LemmyLinkedUrl::parse(null));
        self::assertNull(LemmyLinkedUrl::parse(''));
    }

    public function testLeadingWhitespaceIsSkipped(): void
    {
        $body = "\n  <a href=\"https://x.example/a\">https://x.example/a</a>";

        self::assertSame('https://x.example/a', LemmyLinkedUrl::parse($body));
    }

    public function testARelativeOrNonHttpHrefIsNotTheLink(): void
    {
        self::assertNull(LemmyLinkedUrl::parse('<a href="/post/1">/post/1</a>'));
        self::assertNull(LemmyLinkedUrl::parse('<a href="ftp://x.example/a">ftp://x.example/a</a>'));
    }

    public function testAParagraphWithMoreThanTheAnchorIsNotTheLink(): void
    {
        $body = '<p><a href="https://x.example/a">https://x.example/a</a> and more</p>';

        self::assertNull(LemmyLinkedUrl::parse($body));
    }

    public function testALinkLaterInTheBodyIsNotTheLink(): void
    {
        $body = '<p>See</p><p><a href="https://x.example/a">https://x.example/a</a></p>';

        self::assertNull(LemmyLinkedUrl::parse($body));
    }
}
