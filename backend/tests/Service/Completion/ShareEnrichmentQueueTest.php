<?php

declare(strict_types=1);

namespace App\Tests\Service\Completion;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Service\Completion\ShareEnrichmentQueue;
use App\Tests\DbTestCase;
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
        self::assertSame(ShareKind::Link, $this->reload($share)->getShare()->kind);
    }

    public function testABlueskyPostHoldsTheLinkPlaceholderUntilItsEmbedArrives(): void
    {
        $post = $this->post(self::AT_POST, '<p>Hello</p>');

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertSame(ShareKind::Link, $this->reload($post)->getShare()->kind);
    }

    public function testAPostWithAPictureIsMediaAndNotQueued(): void
    {
        $post = $this->post('https://mastodon.example/@someone/3', '<p>Sunrise.</p>');
        $post->setMedia([new EntryMedium('https://files.example/a.jpg', 'image')], []);

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        self::assertSame(ShareKind::Media, $this->reload($post)->getShare()->kind);
    }

    private function post(string $guid, ?string $contentHtml): Entry
    {
        $entry = $this->entry($guid, $contentHtml);
        $entry->markTitleDerived();

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
