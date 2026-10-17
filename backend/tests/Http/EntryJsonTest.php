<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\ImageRendition;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Enum\CommentsLoad;
use App\Enum\ShareKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Http\EntryJson;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowDiscussion;
use App\Repository\EntryListRowEnrichment;
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Service\LinkCard\Model\PlacedLinkCardModel;
use App\Service\Social\Model\ThreadPostModel;
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

    public function testAQuoteEntrySendsItsQuotedPostOnListAndCompletedRows(): void
    {
        $quoting = $this->entryTitled('Quoting');
        $quoting->shareQuotedPost(new QuotedPostContent(
            'https://bsky.app/profile/q/post/3k',
            'Quoted Person',
            'quoted.bsky.social',
            null,
            null,
            'text',
            [],
            null,
        ));

        self::assertSame('quote', EntryJson::completed($this->row($quoting))['share']);
        $quote = EntryJson::listRow($this->row($quoting))['quote'];
        self::assertNotNull($quote);
        self::assertSame('quoted.bsky.social', $quote['authorHandle']);
        self::assertNull(EntryJson::listRow($this->row($this->entryTitled('Ordinary')))['quote']);
    }

    public function testACardCarriesItsStoredPosition(): void
    {
        $card = new LinkCardContent('https://i.example/chart', 'Chart', null, null, 'i.example');
        $row = $this->row(
            $this->entryTitled('Carded'),
            new EntryListRowEnrichment(linkCards: [new PlacedLinkCardModel(2, $card)]),
        );

        self::assertSame(2, EntryJson::listRow($row)['linkCards'][0]['position']);
    }

    public function testARowCarriesItsThreadWithEachRepliesCardPosition(): void
    {
        $card = new LinkCardContent('https://m.example/a', 'A', null, null, 'm.example');
        $post = new ThreadPostModel(
            'https://x/2',
            new \DateTimeImmutable('2026-10-17T08:15:31Z'),
            '<p>Second &amp; last</p>',
            [],
            new PlacedLinkCardModel(3, $card),
        );
        $row = $this->row($this->entryTitled('Root'), new EntryListRowEnrichment(threadPosts: [$post]));

        $thread = EntryJson::listRow($row)['thread'];

        self::assertSame(
            ['url', 'publishedAt', 'html', 'text', 'media', 'linkCard'],
            array_keys($thread[0]),
        );
        self::assertSame('https://x/2', $thread[0]['url']);
        self::assertSame('2026-10-17T08:15:31+00:00', $thread[0]['publishedAt']);
        self::assertSame('Second & last', $thread[0]['text']);
        self::assertSame(3, $thread[0]['linkCard']['position'] ?? null);
        self::assertSame($thread, EntryJson::completed($row)['thread']);
    }

    public function testARowWithoutAThreadHasAnEmptyOne(): void
    {
        self::assertSame([], EntryJson::listRow($this->row($this->entryTitled('Plain')))['thread']);
    }

    public function testACompletedRowCarriesOnlyWhatACompletionCanChange(): void
    {
        $shared = $this->entryTitled('Shared');
        $shared->setShareKind(ShareKind::Media);

        $card = new LinkCardContent('https://i.example/chart', 'Chart', null, null, 'i.example');
        $row = $this->row($shared, new EntryListRowEnrichment(linkCards: [new PlacedLinkCardModel(0, $card)]));

        $json = EntryJson::completed($row);

        self::assertSame(
            [
                'id', 'pending', 'summary', 'excerpt', 'imageUrl', 'imageWidth', 'imageHeight',
                'imageRenditions', 'media', 'linkCards', 'thread', 'share', 'quote',
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
                'position' => 0,
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

    public function testAnEntryWithoutASocialStateSaysSo(): void
    {
        self::assertNull(EntryJson::listRow($this->row($this->entryTitled('Article')))['social']);
    }

    public function testASocialPostCarriesItsNetworkWhetherTheUserLikedItAndWhetherTheyCan(): void
    {
        $row = $this->row(
            $this->entryTitled('Post'),
            new EntryListRowEnrichment(social: new EntryListRowSocial(SocialNetwork::Bluesky, true, true)),
        );

        self::assertSame(
            ['network' => 'bluesky', 'isLiked' => true, 'canLike' => true],
            EntryJson::detail($row)['social'],
        );
    }

    public function testAPostOnANetworkWithoutAnAccountSaysItCannotBeLiked(): void
    {
        $row = $this->row(
            $this->entryTitled('Post'),
            new EntryListRowEnrichment(social: new EntryListRowSocial(SocialNetwork::Mastodon, false, false)),
        );

        self::assertSame(
            ['network' => 'mastodon', 'isLiked' => false, 'canLike' => false],
            EntryJson::listRow($row)['social'],
        );
    }

    public function testTheEnrichedDiscussionStandsInForTheStoredOne(): void
    {
        $entry = $this->entryTitled('Post');
        $entry->setDiscussion(Discussion::withCommentsFeed(
            'https://example.com/a#comments',
            'https://example.com/a/feed',
            CommentsLoad::Manual,
        ));
        $row = $this->row(
            $entry,
            new EntryListRowEnrichment(
                discussion: new EntryListRowDiscussion('https://example.com/a', CommentsLoad::Auto),
            ),
        );

        $json = EntryJson::listRow($row);

        self::assertSame('auto', $json['comments']);
        self::assertSame('https://example.com/a', $json['discussionUrl']);
    }

    public function testAnArticleKeepsItsOwnDiscussion(): void
    {
        $entry = $this->entryTitled('Article');
        $entry->setDiscussion(Discussion::withCommentsFeed(
            'https://example.com/a#comments',
            'https://example.com/a/feed',
            CommentsLoad::Manual,
        ));

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame('manual', $json['comments']);
        self::assertSame('https://example.com/a#comments', $json['discussionUrl']);
    }

    public function testAnArticleWithoutADiscussionHasNone(): void
    {
        $json = EntryJson::listRow($this->row($this->entryTitled('Article')));

        self::assertNull($json['comments']);
        self::assertNull($json['discussionUrl']);
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
            enrichment: new EntryListRowEnrichment(
                ['Politics'],
                [$savedSearch],
                [new PlacedLinkCardModel(0, $card)],
                true,
            ),
        );

        $json = EntryJson::listRow($row);

        self::assertSame(
            [
                'id', 'pending', 'summary', 'excerpt', 'imageUrl', 'imageWidth', 'imageHeight',
                'imageRenditions', 'media', 'linkCards', 'thread', 'share', 'quote', 'title', 'url', 'discussionUrl',
                'comments',
                'author', 'imageAspectRatio', 'attachments', 'categories', 'publishedAt', 'createdAt',
                'subscriptionId', 'source', 'poster',
                'faviconUrl', 'isHidden', 'isFavorite', 'isKept', 'isViewed',
                'shortPlayerUrl', 'titleDerived', 'savedSearches', 'social', 'duplicates',
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

    public function testAPrivateFeedEntryNamesItsAuthorAsThePosterAndKeepsTheFeedAsTheSource(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::BLUESKY_API,
            new EntryAuthor('POLITICO Europe', 'https://bsky.app/profile/politico.eu', 'https://cdn.example/p.png'),
        );

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame('Source', $json['source']);
        self::assertSame([
            'name' => 'POLITICO Europe',
            'url' => 'https://bsky.app/profile/politico.eu',
            'imageUrl' => 'https://cdn.example/p.png',
        ], $json['poster']);
    }

    public function testAPrivateFeedEntryWithoutAnAuthorHasNoPoster(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::MASTODON_API,
            new EntryAuthor(profileUrl: 'https://bsky.app/profile/politico.eu'),
        );

        self::assertNull(EntryJson::listRow($this->row($entry))['poster']);
    }

    public function testAPrivateFeedEntryWithAnEmptyAuthorHasNoPoster(): void
    {
        $entry = $this->entryOfFeedWithFormat(SourceFormat::MASTODON_API, new EntryAuthor(''));

        self::assertNull(EntryJson::listRow($this->row($entry))['poster']);
    }

    public function testAnOrdinaryFeedEntryHasNoPosterEvenWithAnAuthorProfile(): void
    {
        $entry = $this->entryOfFeedWithFormat(
            SourceFormat::XML,
            new EntryAuthor('A. Writer', 'https://example.com/writer', 'https://cdn.example/writer.png'),
        );

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame('Source', $json['source']);
        self::assertNull($json['poster']);
    }

    public function testAProfileFeedEntryShowsThePersonsPictureAndLinksTheirProfile(): void
    {
        $entry = $this->profileFeedEntry('https://cdn.example/politico.jpg');

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame('Source', $json['source']);
        self::assertSame([
            'name' => 'Source',
            'url' => 'https://bsky.app/profile/politico.eu',
            'imageUrl' => 'https://cdn.example/politico.jpg',
        ], $json['poster']);
    }

    public function testAProfileFeedEntryNeverLinksASiteThatIsNoWebPage(): void
    {
        $entry = $this->profileFeedEntry(null);
        $entry->getFeed()->setSiteUrl('javascript://evil.example/%0Aalert(1)');

        self::assertNull(EntryJson::listRow($this->row($entry))['poster']['url'] ?? null);
        self::assertNotNull(EntryJson::listRow($this->row($entry))['poster']);
    }

    public function testAProfileFeedEntryWithAnAvatarOfItsOwnShowsThatOne(): void
    {
        $entry = $this->profileFeedEntry('https://cdn.example/politico.jpg');
        $entry->setAuthor(new EntryAuthor(avatarUrl: 'https://cdn.example/reposted.jpg'));

        self::assertSame(
            'https://cdn.example/reposted.jpg',
            EntryJson::listRow($this->row($entry))['poster']['imageUrl'] ?? null,
        );
    }

    public function testAProfileFeedEntryWithoutAPictureHasAPosterWithoutAnImage(): void
    {
        $poster = EntryJson::listRow($this->row($this->profileFeedEntry(null)))['poster'];

        self::assertNotNull($poster);
        self::assertNull($poster['imageUrl']);
    }

    public function testAProfileFeedStoredWithoutANetworkHasNoPoster(): void
    {
        $entry = $this->profileFeedEntry('https://cdn.example/politico.jpg');
        $entry->getFeed()->classifyAs(null);

        self::assertNull(EntryJson::listRow($this->row($entry))['poster']);
    }

    private function profileFeedEntry(?string $feedImageUrl): Entry
    {
        $feed = new Feed('https://bsky.app/profile/politico.eu/rss');
        $feed->setSiteUrl('https://bsky.app/profile/politico.eu');
        $feed->setImageUrl($feedImageUrl);
        $feed->classifyAs(SocialNetwork::Bluesky);

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
