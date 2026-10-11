<?php

declare(strict_types=1);

namespace App\Tests\Service\Share\Support;

use App\Service\Share\Support\OwnProse;
use PHPUnit\Framework\TestCase;

final class OwnProseTest extends TestCase
{
    public function testAHackerNewsBodyHasNoOwnText(): void
    {
        $body = '<p>Article URL: <a href="https://bd103.dev/blog/c">https://bd103.dev/blog/c</a></p>'
            . "\n" . '<p>Comments URL: <a href="https://news.ycombinator.com/item?id&#61;5">'
            . 'https://news.ycombinator.com/item?id&#61;5</a></p>' . "\n<p>Points: 110</p>\n<p># Comments: 45</p>";

        self::assertSame('', OwnProse::of($body));
    }

    public function testKeepsProseAndWordedLinks(): void
    {
        $body = '<p>David Smith, <a href="https://mastodon.social/@x/1">last week</a>:</p>'
            . '<blockquote><p>Interesting adoption curve.</p></blockquote>';

        self::assertSame("David Smith, last week:\nInteresting adoption curve.", OwnProse::of($body));
    }

    public function testDropsALinkThatReadsAsItsUrlButKeepsTheSentence(): void
    {
        self::assertSame(
            'Read today.',
            OwnProse::of('<p>Read <a href="https://example.com/a">example.com/a</a> today.</p>'),
        );
    }

    public function testReadsPlainText(): void
    {
        self::assertSame('Just words.', OwnProse::of('Just words.'));
    }
}
