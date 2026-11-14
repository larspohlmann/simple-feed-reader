<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\PlatformEntryRule;

use App\Entity\Headline;
use App\Enum\CommentsLoad;
use App\Service\Ingest\PlatformEntryRule\LemmyEntryRule;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Tests\Support\FeedFormatParsers;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class LemmyEntryRuleTest extends TestCase
{
    use ReadsFixtures;

    private const string LINK_POST = 'https://lemmy.world/post/54385737';
    private const string QUOTE_POST = 'https://lemmy.world/post/54341818';
    private const string TEXT_POST = 'https://lemmy.world/post/53901620';
    private const string HEADER = 'submitted by <a href="https://lemmy.world/u/someone">someone</a> to '
        . '<a href="https://lemmy.world/c/politics">politics</a><br>5 points | '
        . '<a href="https://lemmy.world/post/1">3 comments</a>';

    private function post(string $url): ParsedEntryModel
    {
        foreach (FeedFormatParsers::feed($this->fixture('lemmy/politics.xml'))->entries as $entry) {
            if ($entry->guid === $url) {
                return $entry;
            }
        }

        self::fail('The fixture has no post ' . $url);
    }

    private static function entry(string $guid, ?string $url, string $contentHtml): ParsedEntryModel
    {
        return new ParsedEntryModel($guid, $url, Headline::fromFeed('Title'), 'x', null, $contentHtml, null);
    }

    public function testSupportsAPostWithTheLemmyHeader(): void
    {
        self::assertTrue((new LemmyEntryRule())->supports($this->post(self::LINK_POST)));
        self::assertTrue((new LemmyEntryRule())->supports($this->post(self::TEXT_POST)));
    }

    public function testIgnoresAPostPathWithoutTheHeader(): void
    {
        $entry = self::entry(self::LINK_POST, self::LINK_POST, '<p>No header</p>');

        self::assertFalse((new LemmyEntryRule())->supports($entry));
    }

    public function testIgnoresTheHeaderOffAPostPath(): void
    {
        $entry = self::entry('https://lemmy.world/c/politics', 'https://lemmy.world/c/politics', self::HEADER);

        self::assertFalse((new LemmyEntryRule())->supports($entry));
    }

    public function testIgnoresAPostOnAHostWithAPort(): void
    {
        $entry = self::entry('https://h:8080/post/1', 'https://h:8080/post/1', self::HEADER);

        self::assertFalse((new LemmyEntryRule())->supports($entry));
    }

    public function testSupportsAPostIdentifiedOnlyByItsGuid(): void
    {
        $entry = self::entry(self::LINK_POST, null, self::HEADER);

        self::assertTrue((new LemmyEntryRule())->supports($entry));
    }

    public function testAPostBecomesADiscussionThread(): void
    {
        $result = (new LemmyEntryRule())->apply($this->post(self::LINK_POST));

        self::assertNull($result->url);
        self::assertSame(self::LINK_POST, $result->discussion->url);
        self::assertSame(CommentsLoad::Auto, $result->discussion->commentsLoad);
        self::assertNull($result->discussion->commentsFeedUrl);
        self::assertSame(self::LINK_POST, $result->guid);
    }

    public function testAPostUrlIsTheDiscussionAddressBeforeTheGuid(): void
    {
        $entry = self::entry('tag:lemmy.world,2026:post-1', self::LINK_POST, self::HEADER);

        self::assertSame(self::LINK_POST, (new LemmyEntryRule())->apply($entry)->discussion->url);
    }

    public function testTheHeaderLeavesTheBody(): void
    {
        $link = (new LemmyEntryRule())->apply($this->post(self::LINK_POST));
        $text = (new LemmyEntryRule())->apply($this->post(self::TEXT_POST));
        $quote = (new LemmyEntryRule())->apply($this->post(self::QUOTE_POST));

        $article = 'https://www.businessinsider.com/dhs-demands-50-billion-to-prevent-another-huge-faa-fiasco-2026-11';
        self::assertSame('<a href="' . $article . '">' . $article . '</a>', $link->contentHtml);
        self::assertStringStartsWith('<p>There’s a separate thread', (string) $text->contentHtml);
        self::assertStringStartsWith('<a href="https://www.lgbtqnation.com/', (string) $quote->contentHtml);
        self::assertStringContainsString('<blockquote>', (string) $quote->contentHtml);
        foreach ([$link, $text, $quote] as $result) {
            self::assertStringNotContainsString('submitted by', (string) $result->contentHtml);
            self::assertStringNotContainsString('points', (string) $result->contentHtml);
        }
    }

    public function testTheAuthorIsTheUsername(): void
    {
        $local = (new LemmyEntryRule())->apply($this->post(self::LINK_POST));
        $remote = (new LemmyEntryRule())->apply($this->post(self::QUOTE_POST));

        self::assertSame('inclementimmigrant', $local->author);
        self::assertSame('https://lemmy.world/u/inclementimmigrant', $local->authorUrl);
        self::assertSame('remote', $remote->author);
        self::assertSame('https://feddit.org/u/remote', $remote->authorUrl);
    }
}
