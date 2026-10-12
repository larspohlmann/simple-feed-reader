<?php

declare(strict_types=1);

namespace App\Tests\Service\Completion;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Completion\ShareEnrichmentQueue;
use App\Tests\DbTestCase;
use App\Tests\Support\Bluesky;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ReloadsEntities;
use Symfony\Component\Clock\MockClock;

final class ShareEnrichmentQueueTest extends DbTestCase
{
    use ReloadsEntities;

    private const string AT_POST = 'at://did:plc:test/app.bsky.feed.post/3abc';

    private MockClock $clock;
    private ShareEnrichmentQueue $queue;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'Europe/Berlin');
        $this->queue = PostEnrichers::queue($this->entityManager, $this->clock);
        $this->feed = new Feed('https://mastodon.example/@someone.rss');
        $this->feed->setSiteUrl('https://mastodon.example/@someone');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testABlueskyPostWaitsForTheAppViewAndALinkPostForItsCards(): void
    {
        $blueskyPost = $this->post(self::AT_POST, '<p>See https://example.com/a</p>');
        $linkPost = $this->post('https://mastodon.example/@someone/1', '<p>See https://example.com/a</p>');

        $this->queue->queue([$blueskyPost, $linkPost]);
        $this->entityManager->flush();

        self::assertSame(
            [
                self::AT_POST => ShareEnrichmentKind::AppView,
                'https://mastodon.example/@someone/1' => ShareEnrichmentKind::LinkCards,
            ],
            $this->queuedKinds(),
        );
        self::assertSame('2026-10-11 10:00:00', $this->storedQueuedAt(self::AT_POST));
    }

    public function testALinkPostHasItsBareUrlsLinked(): void
    {
        $post = $this->post(
            'https://mastodon.example/@someone/1',
            '<p>Read https://example.com/a and <a href="https://example.com/b">https://example.com/b</a></p>',
        );

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertStringContainsString(
            'Read <a href="https://example.com/a"',
            (string) $this->reload($post)->getContentHtml(),
        );
    }

    public function testABodyWithNothingToLinkIsStoredUntouched(): void
    {
        $body = '<p><a href="https://example.com/a">https://example.com/a</a></p>';
        $post = $this->post('https://mastodon.example/@someone/1', $body);

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertSame($body, $this->reload($post)->getContentHtml());
        self::assertCount(1, $this->queuedKinds());
    }

    public function testAnArticleAPostSharingNoLinkAndAnEmptyPostAreNotQueued(): void
    {
        $article = $this->entry('https://example.org/article', '<p>See https://example.com/a</p>');
        $hashtagOnly = $this->post(
            'https://mastodon.example/@someone/1',
            '<p>#<a href="https://mastodon.example/tags/x">x</a></p>',
        );
        $empty = $this->post('https://mastodon.example/@someone/2', null);

        $this->queue->queue([$article, $hashtagOnly, $empty]);
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        self::assertSame('<p>See https://example.com/a</p>', $this->reload($article)->getContentHtml());
    }

    public function testATitledShareIsQueuedForItsCard(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $guid = 'https://mastodon.example/@someone/9';
        $share = new Entry($this->feed, $guid, 'https://example.com/story', 'Headline', $now, $now);
        $share->setContentHtml('<p>Short.</p>');
        $share->getImage()->storePending(null, null, null);
        $this->entityManager->persist($share);

        $this->queue->queue([$share]);
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertSame(ShareKind::Link, $this->reload($share)->getShareKind());
    }

    public function testABlueskyPostHoldsTheLinkPlaceholderUntilItsEmbedArrives(): void
    {
        $post = $this->post(self::AT_POST, '<p>Hello</p>');

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertSame(ShareKind::Link, $this->reload($post)->getShareKind());
    }

    public function testAPostWithAPictureIsMediaAndNotQueued(): void
    {
        $post = $this->post('https://mastodon.example/@someone/3', '<p>Sunrise.</p>');
        $post->setMedia([new EntryMedium('https://files.example/a.jpg', 'image')], []);

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        self::assertSame(ShareKind::Media, $this->reload($post)->getShareKind());
    }

    public function testAKnownBlueskyEmbedSettlesThePostWithoutTheAppView(): void
    {
        $guid = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
        $post = $this->post(
            $guid,
            '<p>Progressives are calling on her to step down. www.motherjones.com/politics/202...</p>',
        );

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofBlueskyPosts([$guid => Bluesky::post('external')]),
        );
        $this->entityManager->flush();

        self::assertNotContains(ShareEnrichmentKind::AppView, $this->queuedKinds());
        $stored = $this->reload($post);
        self::assertSame(ShareKind::Link, $stored->getShareKind());
        self::assertCount(1, $this->storedCards($stored));
    }

    public function testAKnownBlueskyPostWithoutAnEmbedOrALinkSharesNothing(): void
    {
        $post = $this->post(self::AT_POST, '<p>Just words.</p>');

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofBlueskyPosts([self::AT_POST => JsonNodeModel::of(['uri' => self::AT_POST])]),
        );
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        self::assertNull($this->reload($post)->getShareKind());
    }

    public function testAKnownBlueskyPostWithALinkAndNoEmbedAwaitsItsLinkCard(): void
    {
        $post = $this->post(self::AT_POST, '<p>See <a href="https://example.com/a">https://example.com/a</a></p>');

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofBlueskyPosts([self::AT_POST => JsonNodeModel::of(['uri' => self::AT_POST])]),
        );
        $this->entityManager->flush();

        self::assertSame([self::AT_POST => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertSame(ShareKind::Link, $this->reload($post)->getShareKind());
    }

    public function testAMastodonCardCardsItsLinkWithoutAPageFetch(): void
    {
        $guid = 'https://mastodon.example/@someone/1';
        $post = $this->post($guid, '<p>Read <a href="https://example.com/story">https://example.com/story</a></p>');
        $card = new LinkCardContent(
            'https://example.com/story',
            'The story',
            'What happened.',
            'https://example.com/story.jpg',
            'Example',
        );

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        $stored = $this->reload($post);
        self::assertSame(ShareKind::Link, $stored->getShareKind());
        self::assertEquals([$card], $this->storedCards($stored));
    }

    public function testACardedMastodonQuoteLosesItsQuoteLine(): void
    {
        $guid = 'https://mastodon.example/@someone/4';
        $post = $this->post(
            $guid,
            '<p>So true.</p><p>RE: <a href="https://other.example/@them/9">https://other.example/@them/9</a></p>',
        );
        $card = new LinkCardContent('https://other.example/@them/9', 'Them', 'The quoted post.', null, 'Other');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        $stored = $this->reload($post);
        self::assertSame('<p>So true.</p>', $stored->getContentHtml());
        self::assertSame('So true.', $stored->getSummary());
        self::assertSame(ShareKind::Link, $stored->getShareKind());
        self::assertEquals([$card], $this->storedCards($stored));
    }

    public function testAPostWithMoreOwnProseThanAPostHoldsIgnoresItsCard(): void
    {
        $guid = 'https://mastodon.example/@someone/5';
        $prose = str_repeat('word ', 101);
        $post = $this->post(
            $guid,
            '<p>' . $prose . '<a href="https://example.com/story">https://example.com/story</a></p>',
        );
        $card = new LinkCardContent('https://example.com/story', 'The story', null, null, 'Example');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        $stored = $this->reload($post);
        self::assertNull($stored->getShareKind());
        self::assertSame([], $this->storedCards($stored));
    }

    public function testACardUnderACanonicalUrlIsFiledUnderThePostedLink(): void
    {
        $guid = 'https://mastodon.example/@someone/6';
        $posted = 'https://example.com/story?utm_source=mastodon';
        $post = $this->post($guid, '<p>So true.</p><p>RE: <a href="' . $posted . '">' . $posted . '</a></p>');
        $card = new LinkCardContent('https://example.com/story', 'The story', 'What happened.', null, 'Example');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        $stored = $this->reload($post);
        self::assertSame('<p>So true.</p>', $stored->getContentHtml());
        self::assertSame(ShareKind::Link, $stored->getShareKind());
        self::assertEquals(
            [new LinkCardContent($posted, 'The story', 'What happened.', null, 'Example')],
            $this->storedCards($stored),
        );
    }

    public function testACardForNoPostedLinkIsFiledUnderTheFirstAndTheOthersWaitForTheirOwn(): void
    {
        $guid = 'https://mastodon.example/@someone/7';
        $post = $this->post(
            $guid,
            '<p><a href="https://example.com/a">https://example.com/a</a>'
            . ' and <a href="https://other.example/b?ref=x">https://other.example/b?ref=x</a></p>',
        );
        $card = new LinkCardContent('https://other.example/b', 'B', null, null, 'Other');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertEquals(
            [new LinkCardContent('https://example.com/a', 'B', null, null, 'Other')],
            $this->storedCards($this->reload($post)),
        );
    }

    public function testALinkTheCardDoesNotCoverStillWaitsForItsOwnCard(): void
    {
        $guid = 'https://mastodon.example/@someone/2';
        $post = $this->post(
            $guid,
            '<p><a href="https://example.com/story">https://example.com/story</a>'
            . ' and <a href="https://other.example/b">https://other.example/b</a></p>',
        );
        $card = new LinkCardContent('https://example.com/story', 'The story', null, null, 'Example');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
    }

    public function testWithoutACardALinkPostWaitsForThePageFetchAsBefore(): void
    {
        $guid = 'https://mastodon.example/@someone/3';
        $post = $this->post($guid, '<p>Read <a href="https://example.com/story">https://example.com/story</a></p>');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::none());
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertSame(ShareKind::Link, $this->reload($post)->getShareKind());
    }

    private function post(string $guid, ?string $contentHtml): Entry
    {
        $entry = $this->entry($guid, $contentHtml);
        $entry->setHeadline(Headline::derived('Post'));

        return $entry;
    }

    private function entry(string $guid, ?string $contentHtml): Entry
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $entry = new Entry($this->feed, $guid, null, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /** @return array<string, ShareEnrichmentKind> by entry guid */
    private function queuedKinds(): array
    {
        $kinds = [];
        foreach ($this->entityManager->getRepository(ShareEnrichment::class)->findBy([], ['id' => 'ASC']) as $row) {
            $kinds[$row->getEntry()->getGuid()] = $row->getKind();
        }

        return $kinds;
    }

    /** @return list<LinkCardContent> */
    private function storedCards(Entry $entry): array
    {
        $cards = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);

        return $cards[$entry->requireId()] ?? [];
    }

    private function storedQueuedAt(string $guid): string
    {
        $queuedAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT pending.queued_at FROM share_enrichment pending'
            . ' JOIN entry ON entry.id = pending.entry_id WHERE entry.guid = ?',
            [$guid],
        );
        self::assertIsString($queuedAt);

        return $queuedAt;
    }
}
