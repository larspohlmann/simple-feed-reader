<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\ImageRendition;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Enum\SourceFormat;
use App\Http\EntryJson;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnrichment;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use PHPUnit\Framework\TestCase;

final class EntryJsonTest extends TestCase
{
    public function testEmitsMediaAndAttachmentsAsStructuredArrays(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/ep',
            'Episode',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->getImage()->storePending('https://i/lead.jpg', 800, 600);
        $entry->setMedia(
            [new EntryMedium('https://i/lead.jpg', 'image', 800, 600)],
            [new EntryAttachment('https://cdn/ep.mp3', 'audio/mpeg', 3723)],
        );

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame(
            [['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800, 'height' => 600]],
            $json['media'],
        );
        self::assertSame(
            [['url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg', 'durationInSeconds' => 3723]],
            $json['attachments'],
        );
    }

    public function testEmitsEmptyMediaListsWhenTheEntryHasNone(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            null,
            'Plain',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame([], $json['media']);
        self::assertSame([], $json['attachments']);
        self::assertSame([], $json['imageRenditions']);
    }

    public function testEmitsTheStoredImageRenditions(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/a',
            'Article',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->getImage()->storePending('https://i/lead-1024.jpg', 1024, 683);
        $entry->getImage()->storeRenditions([
            new ImageRendition('https://i/lead-300.jpg', 300),
            new ImageRendition('https://i/lead-1024.jpg', 1024),
        ]);

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame(
            [
                ['url' => 'https://i/lead-300.jpg', 'width' => 300],
                ['url' => 'https://i/lead-1024.jpg', 'width' => 1024],
            ],
            $json['imageRenditions'],
        );
    }

    public function testEmitsNoRenditionsThatCannotReachTheLeadImage(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/a',
            'Article',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->getImage()->storePending('https://i/lead.jpg', null, null);
        $entry->getImage()->storeRenditions([
            new ImageRendition('https://i/lead-100x100.jpg', 100),
            new ImageRendition('https://i/lead-150x150.jpg', 150),
        ]);

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame([], $json['imageRenditions']);
    }

    public function testEmitsDuplicatesAsFlattenedEntryJson(): void
    {
        $sibling = new Entry(
            new Feed('https://example.com/other-feed'),
            'sibling-guid',
            'https://example.com/dup',
            'Sibling',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/dup',
            'Survivor',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $row = $this->row($entry)->withDuplicates([$this->row($sibling)]);

        $json = EntryJson::listRow($row);

        self::assertCount(1, $json['duplicates']);
        self::assertSame($sibling->getId(), $json['duplicates'][0]['id']);
        self::assertSame('Sibling', $json['duplicates'][0]['title']);
        self::assertSame([], $json['duplicates'][0]['duplicates']);
    }

    public function testFlagsAnEntryWhoseTitleWasDerivedFromItsText(): void
    {
        $derived = $this->entryTitled('Derived');
        $derived->setHeadline(Headline::derived('Derived'));

        self::assertTrue(EntryJson::listRow($this->row($derived))['titleDerived']);
        self::assertFalse(EntryJson::listRow($this->row($this->entryTitled('Ordinary')))['titleDerived']);
    }

    public function testSendsTheShareKind(): void
    {
        $shared = $this->entryTitled('Shared');
        $shared->setShareKind(ShareKind::Link);

        self::assertSame('link', EntryJson::listRow($this->row($shared))['share']);
        self::assertNull(EntryJson::listRow($this->row($this->entryTitled('Ordinary')))['share']);
    }

    public function testACompletedRowCarriesOnlyWhatACompletionCanChange(): void
    {
        $shared = $this->entryTitled('Shared');
        $shared->setShareKind(ShareKind::Media);

        $card = new LinkCardContent('https://i.example/chart', 'Chart', null, null, 'i.example');
        $row = $this->row($shared, new EntryListRowEnrichment(linkCards: [$card]));

        $json = EntryJson::completed($row);

        self::assertSame(
            [
                'id', 'pending', 'summary', 'excerpt', 'imageUrl', 'imageWidth', 'imageHeight',
                'imageRenditions', 'media', 'linkCards', 'share',
            ],
            array_keys($json),
        );
        self::assertSame('media', $json['share']);
        self::assertSame(
            [[
                'url' => 'https://i.example/chart',
                'title' => 'Chart',
                'description' => null,
                'imageUrl' => null,
                'siteName' => 'i.example',
            ]],
            $json['linkCards'],
        );
    }

    public function testAShareWithoutOwnTextHasNoExcerptFromItsBody(): void
    {
        $shared = $this->entryTitled('Shared');
        $shared->setSummary(null);
        $shared->setContentHtml('<p>Article URL: https://example.com/story</p>');
        $shared->setShareKind(ShareKind::Link);

        self::assertSame('', EntryJson::listRow($this->row($shared))['excerpt']);
    }

    public function testListRowHasNoContentHtmlAndCarriesAnExcerpt(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/a',
            'Article',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->setContentHtml('<p>Full body.</p>');
        $entry->setSummary('A short summary.');

        $json = EntryJson::listRow($this->row($entry));

        self::assertArrayNotHasKey('contentHtml', $json);
        self::assertSame('A short summary.', $json['excerpt']);
    }

    public function testListRowCarriesTheEntrysOwnFields(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/a',
            'Article',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->setPublishedAt(new \DateTimeImmutable('2026-09-06T10:00:00Z'));
        $entry->setAuthor(new EntryAuthor('A. Writer'));
        $entry->setSummary('A short summary.');

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame('https://example.com/a', $json['url']);
        self::assertSame('A. Writer', $json['author']);
        self::assertSame('A short summary.', $json['summary']);
        self::assertSame('2026-09-06T10:00:00+00:00', $json['publishedAt']);
        self::assertSame('2026-09-07T00:00:00+00:00', $json['createdAt']);
    }

    public function testListRowKeepsItsShapeAndCarriesTheEnrichment(): void
    {
        $card = new LinkCardContent('https://i.example/chart', 'Chart', null, null, 'i.example');
        $savedSearch = ['id' => 3, 'slug' => '3-climate', 'term' => 'climate'];
        $row = new EntryListRow(
            $this->entryTitled('Enriched'),
            new EntryListRowSubscription(1, 'Source'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
            enrichment: new EntryListRowEnrichment(['Politics'], [$savedSearch], [$card], true),
        );

        $json = EntryJson::listRow($row);

        self::assertSame(
            [
                'id', 'pending', 'summary', 'excerpt', 'imageUrl', 'imageWidth', 'imageHeight',
                'imageRenditions', 'media', 'linkCards', 'share', 'title', 'url', 'discussionUrl', 'comments',
                'author', 'imageAspectRatio', 'attachments', 'categories', 'publishedAt', 'createdAt',
                'subscriptionId', 'subscriptionTitle', 'source', 'sourceUrl', 'sourceImageUrl',
                'faviconUrl', 'isHidden', 'isFavorite', 'isKept', 'isViewed',
                'shortPlayerUrl', 'titleDerived', 'savedSearches', 'duplicates',
            ],
            array_keys($json),
        );
        self::assertSame(['Politics'], $json['categories']);
        self::assertSame([$savedSearch], $json['savedSearches']);
        self::assertSame(['https://i.example/chart'], array_column($json['linkCards'], 'url'));
        self::assertTrue($json['pending']);
    }

    public function testDetailHasContentHtmlAndListShapedDuplicates(): void
    {
        $sibling = new Entry(
            new Feed('https://example.com/other-feed'),
            'sibling-guid',
            'https://example.com/dup',
            'Sibling',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $sibling->setContentHtml('<p>Sibling body.</p>');
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/dup',
            'Survivor',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->setContentHtml('<p>Full body.</p>');
        $row = $this->row($entry)->withDuplicates([$this->row($sibling)]);

        $json = EntryJson::detail($row);

        self::assertSame('<p>Full body.</p>', $json['contentHtml'] ?? null);
        self::assertArrayNotHasKey('contentHtml', $json['duplicates'][0]);
    }

    public function testShipsTheNormalisedPlayerOfAYouTubeShort(): void
    {
        $short = $this->entryAt('https://m.youtube.com/shorts/GhUuOxrCato?feature=share');

        self::assertSame(
            'https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts',
            EntryJson::listRow($this->row($short))['shortPlayerUrl'],
        );
    }

    public function testShipsNoShortPlayerForAnyOtherEntry(): void
    {
        $watchPage = $this->entryAt('https://www.youtube.com/watch?v=GhUuOxrCato');

        self::assertNull(EntryJson::listRow($this->row($watchPage))['shortPlayerUrl']);
        self::assertNull(EntryJson::listRow($this->row($this->entryAt(null)))['shortPlayerUrl']);
    }

    public function testStatesTheCoverAspectOfAShort(): void
    {
        $json = EntryJson::listRow($this->row($this->entryAt('https://www.youtube.com/shorts/GhUuOxrCato')));

        self::assertSame(9 / 16, $json['imageAspectRatio']);
    }

    public function testStatesNoCoverAspectForAnImageShownAsIs(): void
    {
        $json = EntryJson::listRow($this->row($this->entryAt('https://www.youtube.com/watch?v=GhUuOxrCato')));

        self::assertNull($json['imageAspectRatio']);
        $article = $this->entryAt('https://example.com/shorts/GhUuOxrCato');
        self::assertNull(EntryJson::listRow($this->row($article))['imageAspectRatio']);
        self::assertNull(EntryJson::listRow($this->row($this->entryAt(null)))['imageAspectRatio']);
    }

    public function testMarksAShortAmongTheDuplicates(): void
    {
        $sibling = $this->entryAt('https://www.youtube.com/shorts/GhUuOxrCato');
        $row = $this->row($this->entryAt('https://example.com/a'))->withDuplicates([$this->row($sibling)]);

        $json = EntryJson::listRow($row);

        self::assertNull($json['shortPlayerUrl']);
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts',
            $json['duplicates'][0]['shortPlayerUrl'],
        );
    }

    public function testAnUntitledPostHasNoExcerpt(): void
    {
        $entry = $this->entryTitled('');
        $entry->setHeadline(Headline::untitledPost());
        $entry->setContentHtml(
            '<figure class="post-video"><video src="https://v.test/p.m3u8"></video></figure>'
            . '<p><a href="https://bsky.app/profile/a/post/1">Watch on Bluesky</a></p>',
        );

        self::assertSame('', EntryJson::listRow($this->row($entry))['excerpt']);
    }

    public function testAnUntitledPostKeepsTheTextOfItsSummary(): void
    {
        $entry = $this->entryTitled('');
        $entry->setHeadline(Headline::untitledPost());
        $entry->setSummary('Words that live only in the summary.');
        $entry->setContentHtml('<p><a href="https://bsky.app/profile/a/post/1">Watch on Bluesky</a></p>');

        self::assertSame(
            'Words that live only in the summary.',
            EntryJson::listRow($this->row($entry))['excerpt'],
        );
    }

    public function testAnUntitledArticleKeepsItsExcerpt(): void
    {
        $entry = $this->entryTitled('');
        $entry->setContentHtml('<p>Some body text.</p>');

        self::assertSame('Some body text.', EntryJson::listRow($this->row($entry))['excerpt']);
    }

    private function entryAt(?string $url): Entry
    {
        return new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            $url,
            'Video',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
    }

    private function entryTitled(string $title): Entry
    {
        return new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/a',
            $title,
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
    }

    public function testAPrivateFeedEntryNamesItsAuthorAsTheSource(): void
    {
        $entry = $this->entryOfFeedWithFormat(SourceFormat::BLUESKY_API, new EntryAuthor('POLITICO Europe'));

        self::assertSame('POLITICO Europe', EntryJson::listRow($this->row($entry))['source']);
    }

    public function testAPrivateFeedEntryWithoutAnAuthorKeepsTheSubscriptionTitleAsTheSource(): void
    {
        $entry = $this->entryOfFeedWithFormat(SourceFormat::MASTODON_API, new EntryAuthor());

        self::assertSame('Source', EntryJson::listRow($this->row($entry))['source']);
    }

    public function testAPrivateFeedEntryStillNamesTheSubscriptionTitle(): void
    {
        $entry = $this->entryOfFeedWithFormat(SourceFormat::BLUESKY_API, new EntryAuthor('POLITICO Europe'));

        self::assertSame('Source', EntryJson::listRow($this->row($entry))['subscriptionTitle']);
    }

    public function testAnOrdinaryFeedEntryKeepsTheSubscriptionTitleAsTheSource(): void
    {
        $entry = $this->entryOfFeedWithFormat(SourceFormat::XML, new EntryAuthor('A. Writer'));

        self::assertSame('Source', EntryJson::listRow($this->row($entry))['source']);
    }

    public function testAPrivateFeedEntryLinksItsAuthorProfileAsTheSourceUrl(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::BLUESKY_API,
            new EntryAuthor('POLITICO Europe', 'https://bsky.app/profile/politico.eu'),
        );

        self::assertSame('https://bsky.app/profile/politico.eu', EntryJson::listRow($this->row($entry))['sourceUrl']);
    }

    public function testAPrivateFeedEntryExposesItsAuthorAvatarAsTheSourceImage(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::MASTODON_API,
            new EntryAuthor('Bob', avatarUrl: 'https://cdn.example/bob.png'),
        );

        self::assertSame('https://cdn.example/bob.png', EntryJson::listRow($this->row($entry))['sourceImageUrl']);
    }

    public function testAnOrdinaryFeedEntryHasNoSourceImage(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::XML,
            new EntryAuthor('A. Writer', avatarUrl: 'https://cdn.example/writer.png'),
        );

        self::assertNull(EntryJson::listRow($this->row($entry))['sourceImageUrl']);
    }

    public function testAPrivateFeedEntryWithoutAnAuthorHasNoSourceUrl(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::BLUESKY_API,
            new EntryAuthor(profileUrl: 'https://bsky.app/profile/politico.eu'),
        );

        self::assertNull(EntryJson::listRow($this->row($entry))['sourceUrl']);
    }

    public function testAnOrdinaryFeedEntryHasNoSourceUrl(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::XML,
            new EntryAuthor('A. Writer', 'https://example.com/writer'),
        );

        self::assertNull(EntryJson::listRow($this->row($entry))['sourceUrl']);
    }

    public function testAProfileFeedEntryShowsThePersonsPictureAndLinksTheirProfile(): void
    {
        $entry = $this->profileFeedEntry('https://cdn.example/politico.jpg');

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame('Source', $json['source']);
        self::assertSame('https://bsky.app/profile/politico.eu', $json['sourceUrl']);
        self::assertSame('https://cdn.example/politico.jpg', $json['sourceImageUrl']);
    }

    public function testAProfileFeedEntryNeverLinksASiteThatIsNoWebPage(): void
    {
        $entry = $this->profileFeedEntry(null);
        $entry->getFeed()->setSiteUrl('javascript://evil.example/%0Aalert(1)');

        self::assertNull(EntryJson::listRow($this->row($entry))['sourceUrl']);
    }

    public function testAProfileFeedEntryWithAnAvatarOfItsOwnShowsThatOne(): void
    {
        $entry = $this->profileFeedEntry('https://cdn.example/politico.jpg');
        $entry->setAuthor(new EntryAuthor(avatarUrl: 'https://cdn.example/reposted.jpg'));

        self::assertSame('https://cdn.example/reposted.jpg', EntryJson::listRow($this->row($entry))['sourceImageUrl']);
    }

    public function testAProfileFeedEntryWithoutAPictureHasNoSourceImage(): void
    {
        self::assertNull(EntryJson::listRow($this->row($this->profileFeedEntry(null)))['sourceImageUrl']);
    }

    private function profileFeedEntry(?string $feedImageUrl): Entry
    {
        $feed = new Feed('https://bsky.app/profile/politico.eu/rss');
        $feed->setSiteUrl('https://bsky.app/profile/politico.eu');
        $feed->setImageUrl($feedImageUrl);

        return new Entry(
            $feed,
            'guid',
            'https://bsky.app/profile/politico.eu/post/1',
            'Post',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
    }

    private function entryOfFeedWithFormat(string $sourceFormat, EntryAuthor $author): Entry
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setSourceFormat($sourceFormat);
        $entry = new Entry(
            $feed,
            'guid',
            'https://example.com/a',
            'Post',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->setAuthor($author);

        return $entry;
    }

    private function row(Entry $entry, EntryListRowEnrichment $enrichment = new EntryListRowEnrichment()): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'Source'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
            enrichment: $enrichment,
        );
    }
}
