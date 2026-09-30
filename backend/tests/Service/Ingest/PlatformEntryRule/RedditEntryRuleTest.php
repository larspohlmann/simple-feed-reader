<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\PlatformEntryRule;

use App\Enum\CommentsLoad;
use App\Service\Ingest\PlatformEntryRule\RedditEntryRule;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Tests\Support\FeedFormatParsers;
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

    private static function entry(string $url, string $contentHtml): ParsedEntryModel
    {
        return new ParsedEntryModel('t3_1wobnjy', $url, 'Title', '/u/someone', null, $contentHtml, null);
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
        $body = '<div class="md"><p>Question?</p></div>' . self::footer(self::THREAD);

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $body));

        self::assertNull($result->url);
        self::assertSame(self::THREAD, $result->discussion->url);
        self::assertSame(self::THREAD . '.rss', $result->discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $result->discussion->commentsLoad);
        self::assertSame($body, $result->contentHtml);
    }

    public function testLinkPostHasNoArticleAndKeepsItsLinkInTheBody(): void
    {
        $body = '<div class="md"><p>My take.</p></div>' . self::footer('https://nativephp.com/blog/mobile-450');

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $body));

        self::assertNull($result->url);
        self::assertSame($body, $result->contentHtml);
        self::assertSame(self::THREAD, $result->discussion->url);
    }

    public function testAQueryOrFragmentOnTheThreadUrlStaysOutOfTheDiscussionAndCommentsFeed(): void
    {
        $result = (new RedditEntryRule())->apply(
            self::entry(self::THREAD . '?utm_source=share#top', self::footer(self::THREAD)),
        );

        self::assertSame(self::THREAD, $result->discussion->url);
        self::assertSame(self::THREAD . '.rss', $result->discussion->commentsFeedUrl);
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

    public function testFixtureEntriesBecomeThreadsWithAnAutoCommentsFeed(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../Fixtures/reddit/subreddit.atom');
        $feed = FeedFormatParsers::atom10()->parse($document);
        $rule = new RedditEntryRule();

        self::assertNotSame([], $feed->entries);

        foreach ($feed->entries as $entry) {
            self::assertTrue($rule->supports($entry));

            $result = $rule->apply($entry);

            self::assertNull($result->url);
            self::assertStringEndsWith('/.rss', (string) $result->discussion->commentsFeedUrl);
        }
    }
}
