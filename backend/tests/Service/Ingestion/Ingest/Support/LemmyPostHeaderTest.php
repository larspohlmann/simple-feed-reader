<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest\Support;

use App\Entity\DiscussionCounts;
use App\Service\Ingestion\Ingest\Support\LemmyPostHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyPostHeaderTest extends TestCase
{
    private const string LINK = '<a href="https://example.com/a">https://example.com/a</a>';

    private static function header(string $score, string $comments = '3 comments'): string
    {
        return 'submitted by <a href="https://lemmy.world/u/someone">someone</a> to '
            . '<a href="https://lemmy.world/c/politics">politics</a><br>' . $score
            . '<a href="https://lemmy.world/post/1">' . $comments . '</a>';
    }

    /** @return iterable<string, array{string, int}> */
    public static function scores(): iterable
    {
        yield 'positive' => ['535 points | ', 535];
        yield 'negative' => ['-3 points | ', -3];
        yield 'singular' => ['1 point | ', 1];
    }

    /** @return iterable<string, array{string, int}> */
    public static function commentCounts(): iterable
    {
        yield 'plural' => ['3 comments', 3];
        yield 'singular' => ['1 comment', 1];
        yield 'none' => ['0 comments', 0];
    }

    #[DataProvider('scores')]
    public function testReadsTheScore(string $score, int $expected): void
    {
        self::assertSame($expected, LemmyPostHeader::read(self::header($score))?->counts->score);
    }

    #[DataProvider('commentCounts')]
    public function testReadsTheCommentCount(string $label, int $expected): void
    {
        self::assertSame($expected, LemmyPostHeader::read(self::header('5 points | ', $label))?->counts->commentCount);
    }

    public function testACommentLinkWithoutANumberLeavesTheCountUnknown(): void
    {
        $counts = LemmyPostHeader::read(self::header('5 points | ', 'comments'))?->counts;

        self::assertEquals(DiscussionCounts::of(5, null), $counts);
    }

    #[DataProvider('scores')]
    public function testReadsTheAuthorAndTheBodyAfterTheHeader(string $score, int $expectedScore): void
    {
        $post = LemmyPostHeader::read(self::header($score) . '<br>' . self::LINK);

        self::assertNotNull($post);
        self::assertSame('someone', $post->authorName);
        self::assertSame('https://lemmy.world/u/someone', $post->profileUrl);
        self::assertSame(self::LINK, $post->bodyHtml);
        self::assertSame($expectedScore, $post->counts->score);
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

    public function testTheAuthorNameLosesItsSurroundingWhitespace(): void
    {
        $html = str_replace('>someone<', '> someone <', self::header('5 points | '));

        self::assertSame('someone', LemmyPostHeader::read($html)?->authorName);
    }

    public function testAnElementWhereTheHeaderTextBelongsIsNotAPost(): void
    {
        $html = str_replace('submitted by', '<b>submitted by</b>', self::header('5 points | '));

        self::assertNull(LemmyPostHeader::read($html));
    }

    public function testACommentCountThatIsNoLinkIsNotAPost(): void
    {
        $html = str_replace(
            '<a href="https://lemmy.world/post/1">3 comments</a>',
            '<span>3 comments</span>',
            self::header('5 points | '),
        );

        self::assertNull(LemmyPostHeader::read($html));
    }
}
