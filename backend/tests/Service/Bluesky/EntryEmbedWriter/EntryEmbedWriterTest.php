<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\EntryEmbedWriter;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Repository\LinkCardRepository;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriter;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\DbTestCase;
use App\Tests\Support\Bluesky;

final class EntryEmbedWriterTest extends DbTestCase
{
    private const string CARD_THUMB = 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreie2nvxxbwowodsbtm3rksbshxxyrzr7jp6qkebllexxwjkmjs3a4y';
    private const string VIDEO = 'https://video.bsky.app/watch/did%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde/';
    private const string FULLSIZE = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:z72i7hdynmk6r22z27h6tvur/'
        . 'bafkreih3mb3cwnbc5kv5b2qyy24q6banms25i5ut3cbty4ej2x7vjvd6y4';
    private const string STORY_LINK = '<a href="https://example.com/story" rel="noopener noreferrer" target="_blank">'
        . 'https://example.com/story</a>';

    public function testALinkCardLeavesTheBodyAndIsStoredAsACard(): void
    {
        $entry = $this->entry('<p>Read this.<br />' . Bluesky::CARD . '</p>');

        self::assertSame(FillOutcome::Filled, $this->writer()->fill($entry, Bluesky::post('external')));
        $this->entityManager->flush();

        self::assertSame('<p>Read this.</p>', $entry->getContentHtml());
        self::assertSame('Read this.', $entry->getSummary());
        self::assertSame(self::CARD_THUMB, $entry->getImageUrl());
        self::assertEquals([new EntryMedium(self::CARD_THUMB, 'image')], $entry->getMedia());
        self::assertSame('Post', $entry->getTitle());
        self::assertEquals([new LinkCardContent(
            Bluesky::CARD,
            'After ICE Shooting, Progressives Want New York\'s Police Commissioner to Step Down',
            '28-year-old Oscar Belgal still has a bullet lodged in his body.',
            self::CARD_THUMB,
            'motherjones.com',
        )], $this->storedCards($entry));
    }

    public function testAPostWithALinkInItsTextAndNoEmbedBecomesALinkShareAwaitingItsCard(): void
    {
        $entry = $this->entry(
            '<p>v0.69.0 <a href="https://github.com/webx-ui/module-blog">github.com/webx-ui/mod…</a></p>',
        );

        self::assertSame(FillOutcome::AwaitingLinkCards, $this->writer()->fill($entry, self::postWithoutEmbed()));

        self::assertSame(ShareKind::Link, $entry->getShareKind());
        self::assertStringContainsString(
            '<a href="https://github.com/webx-ui/module-blog"',
            (string) $entry->getContentHtml(),
        );
        self::assertStringStartsWith('v0.69.0', (string) $entry->getSummary());
    }

    public function testAPostWhoseFacetsLinkNoWebPageKeepsTheRssBodyAndItsLink(): void
    {
        $entry = $this->entry('<p>Read https://example.com/a</p>');
        $post = JsonNodeModel::of([
            'uri' => 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t',
            'record' => [
                'text' => 'Read @bob',
                'facets' => [[
                    'index' => ['byteStart' => 5, 'byteEnd' => 9],
                    'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:bob']],
                ]],
            ],
        ]);

        self::assertSame(FillOutcome::AwaitingLinkCards, $this->writer()->fill($entry, $post));
        self::assertStringContainsString('https://example.com/a', (string) $entry->getContentHtml());
    }

    public function testABareUrlInTheTextOfAPostWithoutAnEmbedIsLinkedAndAwaitsItsCard(): void
    {
        $entry = $this->entry('<p>v0.69.0 https://github.com/webx-ui/module-blog</p>');

        self::assertSame(FillOutcome::AwaitingLinkCards, $this->writer()->fill($entry, self::postWithoutEmbed()));

        self::assertSame(ShareKind::Link, $entry->getShareKind());
        self::assertStringContainsString(
            '<a href="https://github.com/webx-ui/module-blog"',
            (string) $entry->getContentHtml(),
        );
    }

    public function testAShortenedLinkInAnRssBodyIsSharedByItsFullUrlFromThePostsFacet(): void
    {
        $entry = $this->entry('<p>v0.69.0 github.com/webx-ui/mod…</p>');

        $outcome = $this->writer()->fill($entry, self::postWithLinkFacet('https://github.com/webx-ui/module-blog'));

        self::assertSame(FillOutcome::AwaitingLinkCards, $outcome);
        self::assertSame(ShareKind::Link, $entry->getShareKind());
        self::assertStringContainsString(
            '<a href="https://github.com/webx-ui/module-blog"',
            (string) $entry->getContentHtml(),
        );
        self::assertStringContainsString('github.com/webx-ui/mod…</a>', (string) $entry->getContentHtml());
    }

    public function testALinkFacetThatIsNoWebPageSharesNothing(): void
    {
        $entry = $this->entry('<p>v0.69.0 github.com/webx-ui/mod…</p>');

        $outcome = $this->writer()->fill($entry, self::postWithLinkFacet('javascript:alert(1)'));

        self::assertSame(FillOutcome::Unfilled, $outcome);
        self::assertNull($entry->getShareKind());
    }

    public function testALinkToAnotherBlueskyPostIsNoShare(): void
    {
        $entry = $this->entry('<p>Look https://bsky.app/profile/alice.bsky.social/post/3mxjuesq6v62t</p>');

        self::assertSame(FillOutcome::Unfilled, $this->writer()->fill($entry, self::postWithoutEmbed()));

        self::assertNull($entry->getShareKind());
    }

    public function testAPostWithNeitherAnEmbedNorALinkStaysUnfilledWithoutAShareKind(): void
    {
        $entry = $this->entry('<p>Just words, and a <a href="https://bsky.app/hashtag/php">#php</a> tag.</p>');

        self::assertSame(FillOutcome::Unfilled, $this->writer()->fill($entry, self::postWithoutEmbed()));

        self::assertNull($entry->getShareKind());
        self::assertSame(
            '<p>Just words, and a <a href="https://bsky.app/hashtag/php">#php</a> tag.</p>',
            $entry->getContentHtml(),
        );
    }

    public function testAnotherUrlInTheTextIsLinkedAndAwaitsItsCard(): void
    {
        $entry = $this->entry('<p>See https://example.com/other</p>');

        self::assertSame(FillOutcome::AwaitingLinkCards, $this->writer()->fill($entry, Bluesky::post('external')));

        self::assertSame(
            '<p>See <a href="https://example.com/other" rel="noopener noreferrer" target="_blank">'
            . 'https://example.com/other</a></p>',
            $entry->getContentHtml(),
        );
        self::assertSame('See https://example.com/other', $entry->getSummary());
    }

    public function testTheCardedUrlLinkedInsideTheTextNeedsNoFurtherCard(): void
    {
        $entry = $this->entry('<p>' . Bluesky::CARD . ' is worth a read.</p>');

        self::assertSame(FillOutcome::Filled, $this->writer()->fill($entry, Bluesky::post('external')));

        self::assertStringContainsString('<a href="' . Bluesky::CARD . '"', (string) $entry->getContentHtml());
    }

    public function testAnHttpLinkCardReplacesTheTrailingUrlToo(): void
    {
        $entry = $this->entry('<p>Scores tonight.<br />http://spr.ly/6018AbCdE</p>');

        $this->writer()->fill($entry, JsonNodeModel::of([
            'uri' => 'at://did:plc:a/app.bsky.feed.post/b',
            'embed' => [
                '$type' => 'app.bsky.embed.external#view',
                'external' => ['uri' => 'http://spr.ly/6018AbCdE', 'title' => 'Scores'],
            ],
        ]));
        $this->entityManager->flush();

        self::assertSame('<p>Scores tonight.</p>', $entry->getContentHtml());
        self::assertSame('Scores tonight.', $entry->getSummary());
        self::assertEquals(
            [new LinkCardContent('http://spr.ly/6018AbCdE', 'Scores', null, null, 'spr.ly')],
            $this->storedCards($entry),
        );
    }

    public function testAnUntitledLinkIsLinkedInTheTextAndAwaitsItsCard(): void
    {
        $entry = $this->entry('<p>Read this.<br />https://example.com/story</p>');

        self::assertSame(FillOutcome::AwaitingLinkCards, $this->writer()->fill($entry, self::untitledLinkPost()));
        $this->entityManager->flush();

        self::assertSame(
            '<p>Read this.<br />' . self::STORY_LINK . '</p>',
            $entry->getContentHtml(),
        );
        self::assertSame('Read this. https://example.com/story', $entry->getSummary());
        self::assertSame([], $this->storedCards($entry));
    }

    public function testAnUntitledLinkMissingFromTheTextIsAppendedAsALink(): void
    {
        $entry = $this->entry('<p>Read this: example.com/sto…</p>');

        self::assertSame(FillOutcome::AwaitingLinkCards, $this->writer()->fill($entry, self::untitledLinkPost()));

        self::assertSame(
            '<p>Read this: example.com/sto…</p><p>' . self::STORY_LINK . '</p>',
            $entry->getContentHtml(),
        );
        self::assertSame('Read this: example.com/sto… https://example.com/story', $entry->getSummary());
    }

    public function testAPostThatIsOnlyTheLinkLeavesNoBody(): void
    {
        $entry = $this->entry('<p>' . Bluesky::CARD . '</p>');

        $this->writer()->fill($entry, Bluesky::post('external'));

        self::assertNull($entry->getContentHtml());
        self::assertNull($entry->getSummary());
    }

    public function testAQuoteLeavesNoFigureAndIsStoredAsACardBeforeTheMediasCard(): void
    {
        $entry = $this->entry('<p>Look.</p>');

        $this->writer()->fill($entry, Bluesky::post('record-with-media'));
        $this->entityManager->flush();

        self::assertSame('<p>Look.</p>', $entry->getContentHtml());
        self::assertSame(
            ['Amy Littlefield (@amylittlefield.bsky.social)', 'From the IAmA community on Reddit'],
            $this->storedTitles($entry),
        );
    }

    public function testAQuoteAloneIsStoredAsTheOnlyCardAndSetsNoImage(): void
    {
        $entry = $this->entry('<p>Watch this.</p>');

        $this->writer()->fill($entry, Bluesky::post('record'));
        $this->entityManager->flush();

        self::assertSame('<p>Watch this.</p>', $entry->getContentHtml());
        self::assertNull($entry->getImageUrl());
        self::assertSame(
            ['Mother Jones (@motherjones.com)'],
            $this->storedTitles($entry),
        );
    }

    public function testAPostWithoutALinkCardStoresNone(): void
    {
        $entry = $this->entry('<p>Apples.</p>');

        $this->writer()->fill($entry, Bluesky::post('images'));
        $this->entityManager->flush();

        self::assertSame([], $this->storedCards($entry));
    }

    public function testAVideoAddsThePosterAsImageAndThePlaylistAsMedia(): void
    {
        $entry = $this->entry('<p>Watch.</p>');

        $this->writer()->fill($entry, Bluesky::post('video'));

        self::assertStringStartsWith(
            '<p>Watch.</p><figure class="post-video"><video controls',
            (string) $entry->getContentHtml(),
        );
        self::assertSame(self::VIDEO . 'thumbnail.jpg', $entry->getImageUrl());
        self::assertSame([1080, 1920], [$entry->getImageWidth(), $entry->getImageHeight()]);
        self::assertEquals(
            [
                new EntryMedium(self::VIDEO . 'thumbnail.jpg', 'image', 1080, 1920),
                new EntryMedium(self::VIDEO . 'playlist.m3u8', 'video', 1080, 1920, self::VIDEO . 'thumbnail.jpg'),
            ],
            $entry->getMedia(),
        );
    }

    public function testAnImageTheEntryAlreadyHasIsKeptAndLeadsTheMedia(): void
    {
        $entry = $this->entry('<p>Apples.</p>');
        $entry->getImage()->storePending('https://example.com/own.jpg', 800, 600);

        $this->writer()->fill($entry, Bluesky::post('images'));

        self::assertSame('https://example.com/own.jpg', $entry->getImageUrl());
        self::assertEquals(
            [
                new EntryMedium('https://example.com/own.jpg', 'image', 800, 600),
                new EntryMedium(self::FULLSIZE, 'image', 4000, 3000),
            ],
            $entry->getMedia(),
        );
    }

    public function testAnEmptyBodyGetsTheEmbedAndNoSummary(): void
    {
        $entry = $this->entry(null);

        $this->writer()->fill($entry, Bluesky::post('images'));

        self::assertStringStartsWith(
            '<figure class="post-images"><img src="' . self::FULLSIZE . '"',
            (string) $entry->getContentHtml(),
        );
        self::assertNull($entry->getSummary());
        self::assertSame(self::FULLSIZE, $entry->getImageUrl());
    }

    public function testTheCombinedHtmlIsSanitizedBeforeItIsStored(): void
    {
        $entry = $this->entry('<p onclick="steal()">Hi.</p><script>alert(1)</script>');

        $this->writer()->fill($entry, Bluesky::post('images'));

        $stored = (string) $entry->getContentHtml();
        self::assertStringStartsWith('<p>Hi.</p><figure class="post-images">', $stored);
        self::assertStringNotContainsString('script', $stored);
        self::assertStringNotContainsString('onclick', $stored);
    }

    public function testAPostWithoutAnEmbedLeavesTheEntryAlone(): void
    {
        $entry = $this->entry('<p>Plain.</p>');
        $entry->setSummary('Plain.');

        self::assertSame(
            FillOutcome::Unfilled,
            $this->writer()->fill($entry, JsonNodeModel::of(['uri' => 'at://did:plc:a/app.bsky.feed.post/b'])),
        );

        self::assertSame('<p>Plain.</p>', $entry->getContentHtml());
        self::assertSame('Plain.', $entry->getSummary());
        self::assertNull($entry->getImageUrl());
        self::assertSame([], $entry->getMedia());
    }

    public function testAPostWithALinkCardSharesALink(): void
    {
        $entry = $this->entry('<p>Read this.<br />' . Bluesky::CARD . '</p>');

        $this->writer()->fill($entry, Bluesky::post('external'));

        self::assertSame(ShareKind::Link, $entry->getShareKind());
    }

    public function testAPostAwaitingItsLinkCardSharesALink(): void
    {
        $entry = $this->entry('<p>Read this.<br />https://example.com/story</p>');
        $entry->setShareKind(null);

        $this->writer()->fill($entry, self::untitledLinkPost());

        self::assertSame(ShareKind::Link, $entry->getShareKind());
    }

    public function testAPostWithOnlyImagesSharesMedia(): void
    {
        $entry = $this->entry('<p>Apples.</p>');

        $this->writer()->fill($entry, Bluesky::post('images'));

        self::assertSame(ShareKind::Media, $entry->getShareKind());
    }

    public function testAPostWithOnlyTextSharesNothing(): void
    {
        $entry = $this->entry('<p>Plain.</p>');

        $entry->setShareKind(ShareKind::Link);

        $this->writer()->fill($entry, Bluesky::post('starter-pack'));

        self::assertNull($entry->getShareKind());
    }

    private static function untitledLinkPost(): JsonNodeModel
    {
        return JsonNodeModel::of(Bluesky::untitledLinkView('at://did:plc:a/app.bsky.feed.post/b'));
    }

    private static function postWithLinkFacet(string $uri): JsonNodeModel
    {
        $text = 'v0.69.0 github.com/webx-ui/mod…';

        return JsonNodeModel::of([
            'uri' => 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t',
            'record' => [
                'text' => $text,
                'facets' => [[
                    'index' => ['byteStart' => 8, 'byteEnd' => \strlen($text)],
                    'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $uri]],
                ]],
            ],
        ]);
    }

    private static function postWithoutEmbed(): JsonNodeModel
    {
        return JsonNodeModel::of(['uri' => 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t']);
    }

    private function entry(?string $contentHtml): Entry
    {
        $feed = new Feed('https://bsky.app/profile/motherjones.com/rss');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t',
            null,
            'Post',
            new \DateTimeImmutable('2026-10-10 12:00:00'),
            new \DateTimeImmutable('2026-10-10 12:00:00'),
        );
        $entry->setContentHtml($contentHtml);
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<LinkCardContent> */
    private function storedCards(Entry $entry): array
    {
        $this->entityManager->clear();

        return $this->linkCards()->contentsByEntryIds([$entry->requireId()])[$entry->requireId()] ?? [];
    }

    /** @return list<string> */
    private function storedTitles(Entry $entry): array
    {
        return array_map(static fn (LinkCardContent $card): string => $card->title, $this->storedCards($entry));
    }

    private function linkCards(): LinkCardRepository
    {
        /** @var LinkCardRepository $linkCards */
        $linkCards = $this->entityManager->getRepository(LinkCard::class);

        return $linkCards;
    }

    private function writer(): EntryEmbedWriter
    {
        return new EntryEmbedWriter(
            new PostEmbedRenderer(),
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
            $this->linkCards(),
        );
    }
}
