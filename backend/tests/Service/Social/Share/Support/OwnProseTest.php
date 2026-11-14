<?php

declare(strict_types=1);

namespace App\Tests\Service\Share\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Share\Support\OwnProse;
use PHPUnit\Framework\TestCase;

final class OwnProseTest extends TestCase
{
    private static function ownProse(string $html): string
    {
        return OwnProse::of(HtmlDocumentParser::parseFragment($html)->body);
    }

    public function testAHackerNewsBodyHasNoOwnText(): void
    {
        $body = '<p>Article URL: <a href="https://bd103.dev/blog/c">https://bd103.dev/blog/c</a></p>'
            . "\n" . '<p>Comments URL: <a href="https://news.ycombinator.com/item?id&#61;5">'
            . 'https://news.ycombinator.com/item?id&#61;5</a></p>' . "\n<p>Points: 110</p>\n<p># Comments: 45</p>";

        self::assertSame('', self::ownProse($body));
    }

    public function testKeepsProseAndWordedLinks(): void
    {
        $body = '<p>David Smith, <a href="https://mastodon.social/@x/1">last week</a>:</p>'
            . '<blockquote><p>Interesting adoption curve.</p></blockquote>';

        self::assertSame("David Smith, last week:\nInteresting adoption curve.", self::ownProse($body));
    }

    public function testDropsALinkThatReadsAsItsUrlButKeepsTheSentence(): void
    {
        self::assertSame(
            'Read today.',
            self::ownProse('<p>Read <a href="https://example.com/a">example.com/a</a> today.</p>'),
        );
    }

    public function testDropsALinkWhoseUrlTextIsSplitAcrossInlineMarkup(): void
    {
        $body = '<p>Visit <a href="https://shop.joinmastodon.org/"><span class="invisible">https://</span>'
            . '<span>shop.joinmastodon.org/</span><span class="invisible"></span></a> today.</p>';

        self::assertSame('Visit today.', self::ownProse($body));
    }

    public function testDropsALinkWhoseUrlTextHasLeadingWhitespace(): void
    {
        self::assertSame(
            'Read today.',
            self::ownProse("<p>Read <a href=\"https://example.com/a\">\n https://example.com/a</a> today.</p>"),
        );
    }

    public function testKeepsAWordedLinkWrappedInInlineMarkup(): void
    {
        self::assertSame(
            'Read the post now.',
            self::ownProse('<p>Read <a href="https://example.com/a"><span>the post</span></a> now.</p>'),
        );
    }

    public function testReadsNothingFromNoBody(): void
    {
        self::assertSame('', OwnProse::of(null));
    }

    public function testReadsPlainText(): void
    {
        self::assertSame('Just words.', self::ownProse('Just words.'));
    }
}
