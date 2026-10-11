<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261011150000;
use Psr\Log\NullLogger;

final class Version20261011150000Test extends DbTestCase
{
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new Feed('https://mastodon.example/@someone.rss');
        $this->entityManager->persist($this->feed);
    }

    public function testAStoredPostSharingALinkIsQueuedForItsCards(): void
    {
        $this->post('https://mastodon.example/@someone/1', '<p>Read HTTPS://example.com/a</p>');
        $this->entityManager->flush();

        $this->backfill();

        self::assertSame(['https://mastodon.example/@someone/1' => 'link_cards'], $this->queuedKinds());
        self::assertSame(1, $this->stillPending());
    }

    public function testArticlesBlueskyPostsLinklessPostsAndKnownPostsAreLeftAlone(): void
    {
        $this->entry('https://example.org/article', '<p>See https://example.com/a</p>');
        $this->post('at://did:plc:test/app.bsky.feed.post/3abc', '<p>See https://example.com/a</p>');
        $this->post('https://mastodon.example/@someone/2', '<p>No link here</p>');
        $this->post('https://mastodon.example/@someone/3', null);
        $appView = $this->post('https://mastodon.example/@someone/4', '<p>See https://example.com/a</p>');
        $this->entityManager->persist(new PostEnrichment(
            $appView,
            PostEnrichmentKind::AppView,
            new \DateTimeImmutable('2026-10-11 09:00:00'),
        ));
        $carded = $this->post('https://mastodon.example/@someone/5', '<p>See https://example.com/b</p>');
        $this->entityManager->persist(new LinkCard(
            $carded,
            0,
            new LinkCardContent('https://example.com/b', 'B', null, null, 'example.com'),
        ));
        $this->entityManager->flush();

        $this->backfill();

        self::assertSame(['https://mastodon.example/@someone/4' => 'appview'], $this->queuedKinds());
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

    private function backfill(): void
    {
        // Migration classes are deliberately excluded from Composer's autoloader (doctrine_migrations.yaml).
        require_once dirname(__DIR__, 2) . '/migrations/Version20261011150000.php';
        $connection = $this->entityManager->getConnection();
        $migration = new Version20261011150000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        $this->entityManager->clear();
    }

    /** @return array<string, string> kind by entry guid */
    private function queuedKinds(): array
    {
        $kinds = [];
        foreach ($this->entityManager->getRepository(PostEnrichment::class)->findBy([], ['id' => 'ASC']) as $row) {
            $kinds[$row->getEntry()->getGuid()] = $row->getKind()->value;
        }

        return $kinds;
    }

    private function stillPending(): int
    {
        return \count($this->entityManager->getRepository(PostEnrichment::class)->findBy(['doneAt' => null]));
    }
}
