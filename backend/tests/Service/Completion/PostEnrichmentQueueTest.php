<?php

declare(strict_types=1);

namespace App\Tests\Service\Completion;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use App\Service\Completion\PostEnrichmentQueue;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ReloadsEntities;
use Symfony\Component\Clock\MockClock;

final class PostEnrichmentQueueTest extends DbTestCase
{
    use ReloadsEntities;

    private const string AT_POST = 'at://did:plc:test/app.bsky.feed.post/3abc';
    private const string LINK_POST = 'https://mastodon.example/@someone/1';

    private MockClock $clock;
    private PostEnrichmentQueue $queue;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'Europe/Berlin');
        $this->queue = PostEnrichers::queue($this->entityManager, $this->clock);
        $this->feed = new Feed('https://mastodon.example/@someone.rss');
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
                self::AT_POST => PostEnrichmentKind::AppView,
                'https://mastodon.example/@someone/1' => PostEnrichmentKind::LinkCards,
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

    public function testAnOpenedPostFromBeforeLinkCardsIsQueuedAndLinkedOnce(): void
    {
        $post = $this->post('https://mastodon.example/@someone/1', '<p>Read https://example.com/a</p>');
        $this->entityManager->flush();

        $this->queue->queueOpened($post);
        $this->queue->queueOpened($post);

        self::assertSame([self::LINK_POST => PostEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertStringContainsString(
            '<a href="https://example.com/a"',
            (string) $this->reload($post)->getContentHtml(),
        );
    }

    public function testAnOpenedPostThatIsQueuedOrHasCardsIsLeftAlone(): void
    {
        $queued = $this->post('https://mastodon.example/@someone/1', '<p>Read https://example.com/a</p>');
        $this->entityManager->persist(new PostEnrichment($queued, PostEnrichmentKind::LinkCards, $this->clock->now()));
        $carded = $this->post('https://mastodon.example/@someone/2', '<p>Read https://example.com/b</p>');
        $this->entityManager->persist(new LinkCard(
            $carded,
            0,
            new LinkCardContent('https://example.com/b', 'B', null, null, 'example.com'),
        ));
        $this->entityManager->flush();

        $this->queue->queueOpened($queued);
        $this->queue->queueOpened($carded);

        self::assertSame([self::LINK_POST => PostEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertSame('<p>Read https://example.com/b</p>', $this->reload($carded)->getContentHtml());
    }

    public function testAnOpenedArticleOrBlueskyPostIsNotQueued(): void
    {
        $article = $this->entry('https://example.org/article', '<p>See https://example.com/a</p>');
        $blueskyPost = $this->post(self::AT_POST, '<p>See https://example.com/a</p>');
        $this->entityManager->flush();

        $this->queue->queueOpened($article);
        $this->queue->queueOpened($blueskyPost);

        self::assertSame([], $this->queuedKinds());
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

    /** @return array<string, PostEnrichmentKind> by entry guid */
    private function queuedKinds(): array
    {
        $kinds = [];
        foreach ($this->entityManager->getRepository(PostEnrichment::class)->findBy([], ['id' => 'ASC']) as $row) {
            $kinds[$row->getEntry()->getGuid()] = $row->getKind();
        }

        return $kinds;
    }

    private function storedQueuedAt(string $guid): string
    {
        $queuedAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT pending.queued_at FROM post_enrichment pending'
            . ' JOIN entry ON entry.id = pending.entry_id WHERE entry.guid = ?',
            [$guid],
        );
        self::assertIsString($queuedAt);

        return $queuedAt;
    }
}
