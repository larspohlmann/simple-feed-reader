<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Support;

use App\Service\Ingest\Support\LemmyPostHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyPostHeaderTest extends TestCase
{
    private const string LINK = '<a href="https://example.com/a">https://example.com/a</a>';

    private static function header(string $score): string
    {
        return 'submitted by <a href="https://lemmy.world/u/someone">someone</a> to '
            . '<a href="https://lemmy.world/c/politics">politics</a><br>' . $score
            . '<a href="https://lemmy.world/post/1">3 comments</a>';
    }

    /** @return iterable<string, array{string}> */
    public static function scores(): iterable
    {
        yield 'positive' => ['535 points | '];
        yield 'negative' => ['-3 points | '];
        yield 'singular' => ['1 point | '];
    }

    #[DataProvider('scores')]
    public function testReadsTheAuthorAndTheBodyAfterTheHeader(string $score): void
    {
        $post = LemmyPostHeader::read(self::header($score) . '<br>' . self::LINK);

        self::assertNotNull($post);
        self::assertSame('someone', $post->authorName);
        self::assertSame('https://lemmy.world/u/someone', $post->profileUrl);
        self::assertSame(self::LINK, $post->bodyHtml);
    }

    public function testAHeaderWithoutBodyLeavesAnEmptyBody(): void
    {
        $post = LemmyPostHeader::read(self::header('5 points | '));

        self::assertNotNull($post);
        self::assertSame('', $post->bodyHtml);
    }

    public function testATextPostBodyFollowsTheHeaderDirectly(): void
    {
        $post = LemmyPostHeader::read(self::header('5 points | ') . '<p>Hello.</p>');

        self::assertNotNull($post);
        self::assertSame('<p>Hello.</p>', $post->bodyHtml);
    }

    public function testABodyWithoutTheHeaderIsNotAPost(): void
    {
        self::assertNull(LemmyPostHeader::read('<p>Just text</p>'));
        self::assertNull(LemmyPostHeader::read(''));
    }

    public function testARedditFooterIsNotAPost(): void
    {
        $footer = ' &#32; submitted by &#32; <a href="https://www.reddit.com/user/someone"> /u/someone </a> <br/>'
            . ' <span><a href="https://example.com">[link]</a></span>';

        self::assertNull(LemmyPostHeader::read($footer));
    }

    public function testAProfileLinkOutsideAUserPathIsNotAPost(): void
    {
        $html = str_replace('/u/someone', '/x/someone', self::header('5 points | '));

        self::assertNull(LemmyPostHeader::read($html));
    }
}
