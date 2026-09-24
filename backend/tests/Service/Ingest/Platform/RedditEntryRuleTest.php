<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Platform;

use App\Enum\CommentsLoad;
use App\Service\Ingest\Platform\RedditEntryRule;
use App\Service\Parser\Atom10Parser;
use App\Service\Parser\ParsedEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedditEntryRuleTest extends TestCase
{
    private const string THREAD = 'https://www.reddit.com/r/PHP/comments/1wobnjy/nativephp_mobile_450/';

    private static function footer(string $linkTarget): string
    {
        return ' &#32; submitted by &#32; <a href="https://www.reddit.com/user/someone"> /u/someone </a> <br/>'
            . ' <span><a href="' . $linkTarget . '">[link]</a></span> &#32; <span><a href="' . self::THREAD
            . '">[comments]</a></span>';
    }

    private static function entry(string $url, string $contentHtml): ParsedEntry
    {
        return new ParsedEntry('t3_1wobnjy', $url, 'Title', '/u/someone', null, $contentHtml, null);
    }

    public function testSupportsAThreadUrl(): void
    {
        self::assertTrue((new RedditEntryRule())->supports(self::entry(self::THREAD, '')));
    }

    public function testIgnoresOtherHostsAndNonThreadPaths(): void
    {
        $rule = new RedditEntryRule();

        self::assertFalse($rule->supports(self::entry('https://example.com/r/PHP/comments/1/x/', '')));
        self::assertFalse($rule->supports(self::entry('https://www.reddit.com/r/PHP/', '')));
    }

    public function testSelfPostHasNoArticleAndAnAutoCommentsFeed(): void
    {
        $body = '<div class="md"><p>Question?</p></div>';

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $body . self::footer(self::THREAD)));

        self::assertNull($result->url);
        self::assertSame(self::THREAD, $result->discussion->url);
        self::assertSame(self::THREAD . '.rss', $result->discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $result->discussion->commentsLoad);
        self::assertSame($body, $result->contentHtml);
    }

    public function testLinkPostPointsAtTheExternalArticle(): void
    {
        $result = (new RedditEntryRule())->apply(
            self::entry(self::THREAD, self::footer('http://nativephp.com/blog/nativephp-mobile-450')),
        );

        self::assertSame('http://nativephp.com/blog/nativephp-mobile-450', $result->url);
        self::assertSame(self::THREAD, $result->discussion->url);
    }

    public function testAnEarlierSubmittedByInTheBodySurvivesTheFooterStrip(): void
    {
        $body = '<div class="md"><p>This patch was submitted by my colleague.</p>'
            . '<p>Second paragraph.</p></div>';

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $body . self::footer(self::THREAD)));

        self::assertSame($body, $result->contentHtml);
        self::assertNull($result->url);
    }

    public function testALinkAnchorInTheBodyIsNotMistakenForTheFooterArticle(): void
    {
        $body = '<div class="md"><p>See <a href="https://example.org/other">[link]</a> for context.</p></div>';

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $body . self::footer(self::THREAD)));

        self::assertNull($result->url);
        self::assertStringContainsString('https://example.org/other', (string) $result->contentHtml);
    }

    public function testTheArticleUrlIsEntityDecoded(): void
    {
        $result = (new RedditEntryRule())->apply(
            self::entry(self::THREAD, self::footer('https://example.org/a?b=1&amp;c=it&#039;s')),
        );

        self::assertSame("https://example.org/a?b=1&c=it's", $result->url);
    }

    /** @return iterable<string, array{string}> */
    public static function nonAbsoluteTargets(): iterable
    {
        yield 'site-relative path' => ['/r/PHP/wiki/index'];
        yield 'protocol-relative' => ['//example.org/article'];
        yield 'foreign scheme' => ['javascript:alert(1)'];
    }

    #[DataProvider('nonAbsoluteTargets')]
    public function testOnlyAnAbsoluteHttpTargetBecomesTheArticle(string $target): void
    {
        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, self::footer($target)));

        self::assertNull($result->url);
    }

    /** @return iterable<string, array{string}> */
    public static function redditHostedTargets(): iterable
    {
        yield 'image' => ['https://i.redd.it/abc123.jpeg'];
        yield 'video' => ['https://v.redd.it/abc123'];
        yield 'gallery' => ['https://www.reddit.com/gallery/1wobnjy'];
        yield 'crosspost' => ['https://www.reddit.com/r/other/comments/9zz/title/'];
    }

    #[DataProvider('redditHostedTargets')]
    public function testRedditHostedTargetsAreNoArticle(string $target): void
    {
        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, self::footer($target)));

        self::assertNull($result->url);
    }

    public function testTableLayoutSurvivesTheFooterStrip(): void
    {
        $html = '<table> <tr><td> <a href="' . self::THREAD . '">'
            . '<img src="https://b.thumbs.redditmedia.com/t.jpg" alt="" /></a> </td><td>'
            . self::footer('https://i.redd.it/abc.jpeg') . ' </td></tr></table>';

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $html));

        self::assertStringContainsString(
            '<img src="https://b.thumbs.redditmedia.com/t.jpg" alt="" />',
            (string) $result->contentHtml,
        );
        self::assertStringNotContainsString('submitted by', (string) $result->contentHtml);
        self::assertStringContainsString('</td></tr></table>', (string) $result->contentHtml);
    }

    public function testKeepsEveryOtherField(): void
    {
        $original = self::entry(self::THREAD, self::footer(self::THREAD));

        $result = (new RedditEntryRule())->apply($original);

        self::assertSame($original->guid, $result->guid);
        self::assertSame($original->title, $result->title);
        self::assertSame($original->author, $result->author);
        self::assertSame($original->media, $result->media);
    }

    public function testFixtureEntriesLoseTheirFooterAndGetAnAutoCommentsFeed(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../Fixtures/reddit/subreddit.atom');
        $feed = (new Atom10Parser())->parse($document);
        $rule = new RedditEntryRule();

        self::assertNotSame([], $feed->entries);

        $articles = [];
        foreach ($feed->entries as $entry) {
            self::assertTrue($rule->supports($entry));

            $result = $rule->apply($entry);

            self::assertStringNotContainsString('submitted by', (string) $result->contentHtml);
            self::assertStringEndsWith('/.rss', (string) $result->discussion->commentsFeedUrl);
            $articles[$result->guid] = $result->url;
        }

        self::assertSame(
            ['t3_1wm4cvh' => null, 't3_1wobnjy' => 'http://nativephp.com/blog/nativephp-mobile-450'],
            $articles,
        );
    }
}
