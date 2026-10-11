<?php

declare(strict_types=1);

namespace App\Tests\Service\Share;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Share;
use App\Enum\CommentsLoad;
use App\Service\Share\EntryShareClassifier;
use PHPUnit\Framework\TestCase;

final class EntryShareClassifierTest extends TestCase
{
    private const string HN_SITE = 'https://news.ycombinator.com/';
    private const string HN_ITEM = 'https://news.ycombinator.com/item?id=5';
    private const string HN_BODY = '<p>Article URL: '
        . '<a href="https://ghuntley.com/unikernels/">https://ghuntley.com/unikernels/</a></p>'
        . '<p>Comments URL: '
        . '<a href="https://news.ycombinator.com/item?id&#61;5">https://news.ycombinator.com/item?id&#61;5</a></p>'
        . '<p>Points: 110</p><p># Comments: 45</p>';

    private function classified(Entry $entry): Entry
    {
        (new EntryShareClassifier())->classify($entry);

        return $entry;
    }

    private static function entry(string $siteUrl, string $guid, ?string $url, ?string $body): Entry
    {
        $feed = new Feed('https://feeds.example/feed');
        $feed->setSiteUrl($siteUrl);
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $entry = new Entry($feed, $guid, $url, 'A real headline', $now, $now);
        $entry->setContentHtml($body);

        return $entry;
    }

    private static function hackerNews(string $target): Entry
    {
        $entry = self::entry(self::HN_SITE, self::HN_ITEM, $target, self::HN_BODY);
        $entry->setDiscussion(Discussion::of(self::HN_ITEM, null, CommentsLoad::Manual));

        return $entry;
    }

    /** @param list<EntryMedium> $media */
    private static function post(string $body, array $media = []): Entry
    {
        $postUrl = 'https://mastodon.example/@someone/1';
        $entry = self::entry('https://mastodon.example/@someone', $postUrl, $postUrl, $body);
        $entry->markTitleDerived();
        $entry->setMedia($media, []);

        return $entry;
    }

    public function testAHackerNewsItemSharesItsArticleWithoutOwnText(): void
    {
        $entry = $this->classified(self::hackerNews('https://ghuntley.com/unikernels/'));

        self::assertEquals(Share::link('https://ghuntley.com/unikernels/'), $entry->getShare());
        self::assertNull($entry->getSummary());
    }

    public function testATitledShareOfAnImageFileIsMediaWithTheFileFirst(): void
    {
        $entry = self::hackerNews('https://i.example/chart.png');
        $entry->setMedia([new EntryMedium('https://cdn.example/thumb.jpg', 'image')], []);

        $this->classified($entry);

        self::assertEquals(Share::media('https://i.example/chart.png'), $entry->getShare());
        self::assertSame('https://i.example/chart.png', $entry->getMedia()[0]->url);
        self::assertCount(2, $entry->getMedia());
    }

    public function testATitledShareOfAnImageFileAlreadyAmongItsMediaMovesItFirst(): void
    {
        $entry = self::hackerNews('https://i.example/chart.png');
        $entry->setMedia([
            new EntryMedium('https://cdn.example/thumb.jpg', 'image'),
            new EntryMedium('https://i.example/chart.png', 'image', 800, 600),
        ], []);

        $this->classified($entry);

        self::assertEquals(
            [
                new EntryMedium('https://i.example/chart.png', 'image', 800, 600),
                new EntryMedium('https://cdn.example/thumb.jpg', 'image'),
            ],
            $entry->getMedia(),
        );
    }

    public function testALinkBlogItemWithShortCommentaryKeepsItAsItsText(): void
    {
        $entry = $this->classified(self::entry(
            'https://daringfireball.net/',
            'tag:daringfireball.net,2026:/linked//6.42',
            'https://x.com/bzamayo/status/1',
            '<p>Benjamin Mayo, commenting:</p><blockquote><p>There needs to be a name for this.</p></blockquote>',
        ));

        self::assertEquals(Share::link('https://x.com/bzamayo/status/1'), $entry->getShare());
        self::assertSame("Benjamin Mayo, commenting:\nThere needs to be a name for this.", $entry->getSummary());
    }

    public function testASummaryOnlyShareIsMeasuredByItsSummaryWhichNeverExceedsTheLimit(): void
    {
        $entry = self::hackerNews('https://ghuntley.com/unikernels/');
        $entry->setContentHtml(null);
        $entry->setSummary(str_repeat('a', EntryShareClassifier::OWN_PROSE_MAX));

        $this->classified($entry);

        self::assertEquals(Share::link('https://ghuntley.com/unikernels/'), $entry->getShare());
        self::assertSame(str_repeat('a', EntryShareClassifier::OWN_PROSE_MAX), $entry->getSummary());
    }

    public function testAShareWithABodyIsMeasuredByItsBodyNotItsSummary(): void
    {
        $entry = self::hackerNews('https://ghuntley.com/unikernels/');
        $entry->setContentHtml('<p>Commentary.</p>');
        $entry->setSummary('A feed snippet.');

        self::assertSame('Commentary.', $this->classified($entry)->getSummary());
    }

    public function testALinkBlogEssayIsAnArticle(): void
    {
        $entry = $this->classified(self::entry(
            'https://daringfireball.net/',
            'tag:daringfireball.net,2026:/linked//6.43',
            'https://mastodon.social/@x/1',
            '<p>' . str_repeat('Long commentary. ', 40) . '</p>',
        ));

        self::assertTrue($entry->getShare()->isNone());
        self::assertNull($entry->getSummary());
    }

    public function testASisterBrandTeaserIsAnArticle(): void
    {
        $entry = $this->classified(
            self::entry('https://www.zdfheute.de/', 'SCMS_1', 'https://www.3sat.de/a', '<p>Teaser.</p>'),
        );

        self::assertTrue($entry->getShare()->isNone());
    }

    public function testANewsTeaserWithAnImageIsAnArticle(): void
    {
        $article = 'https://www.zeit.de/a';
        $entry = self::entry('https://www.zeit.de/', $article, $article, '<p>Teaser.</p>');
        $entry->setMedia([new EntryMedium('https://img.zeit.de/a.jpg', 'image')], []);

        self::assertTrue($this->classified($entry)->getShare()->isNone());
    }

    public function testAPostSharingALinkIsALinkShareWithoutATarget(): void
    {
        $entry = $this->classified(
            self::post('<p>Look: <a href="https://example.com/a">https://example.com/a</a></p>'),
        );

        self::assertEquals(Share::link(null), $entry->getShare());
    }

    public function testAMastodonPostWhoseLinkTextIsSplitIntoSpansKeepsItsLinkCard(): void
    {
        $body = (string) file_get_contents(__DIR__ . '/../../Fixtures/Mastodon/discovery-week-post.html');

        self::assertEquals(Share::link(null), $this->classified(self::post($body))->getShare());
    }

    public function testAPostWithAPictureAndNoLinkIsMedia(): void
    {
        $picture = new EntryMedium('https://files.example/a.jpg', 'image');
        $entry = $this->classified(self::post('<p>Sunrise.</p>', [$picture]));

        self::assertEquals(Share::media(null), $entry->getShare());
    }

    public function testAPostUpToTheLimitKeepsItsCardAndALongerOneDoesNot(): void
    {
        $link = ' <a href="https://example.com/a">https://example.com/a</a>';
        $atLimit = $this->classified(self::post('<p>' . str_repeat('a', 500) . $link . '</p>'));
        $overLimit = $this->classified(self::post('<p>' . str_repeat('a', 501) . $link . '</p>'));

        self::assertEquals(Share::link(null), $atLimit->getShare());
        self::assertTrue($overLimit->getShare()->isNone());
    }

    public function testAPostIsMeasuredInCharactersNotBytes(): void
    {
        $link = ' <a href="https://example.com/a">https://example.com/a</a>';
        $entry = $this->classified(self::post('<p>' . str_repeat('ä', 300) . $link . '</p>'));

        self::assertEquals(Share::link(null), $entry->getShare());
    }

    public function testAPostLeavesItsSummaryAlone(): void
    {
        $entry = self::post('<p>Look: <a href="https://example.com/a">https://example.com/a</a></p>');
        $entry->setSummary('Look: https://example.com/a');

        self::assertSame('Look: https://example.com/a', $this->classified($entry)->getSummary());
    }

    public function testAYouTubeShortIsMediaWhateverItsDescription(): void
    {
        $entry = $this->classified(self::entry(
            'https://www.youtube.com/channel/UC1',
            'yt:video:abc',
            'https://www.youtube.com/shorts/abcdefghijk',
            '<p>' . str_repeat('Description. ', 60) . '</p>',
        ));

        self::assertEquals(Share::media(null), $entry->getShare());
    }

    public function testAShortsLongDescriptionIsCutToTheLimitInWholeCharacters(): void
    {
        $entry = $this->classified(self::entry(
            'https://www.youtube.com/channel/UC1',
            'yt:video:abc',
            'https://www.youtube.com/shorts/abcdefghijk',
            '<p>a' . str_repeat('ü', 600) . '</p>',
        ));

        $summary = (string) $entry->getSummary();
        self::assertTrue(mb_check_encoding($summary, 'UTF-8'));
        self::assertSame(EntryShareClassifier::OWN_PROSE_MAX, mb_strlen($summary));
    }

    public function testARegularYouTubeVideoIsAnArticle(): void
    {
        $entry = $this->classified(self::entry(
            'https://www.youtube.com/channel/UC1',
            'yt:video:abc',
            'https://www.youtube.com/watch?v=abcdefghijk',
            '<p>Description.</p>',
        ));

        self::assertTrue($entry->getShare()->isNone());
    }
}
