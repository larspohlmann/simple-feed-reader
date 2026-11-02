<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Social\Exception\SocialPostRemovedException;
use App\Tests\DbTestCase;
use App\Tests\Support\ConfirmedDeletions;
use App\Tests\Support\FakeHomeServerCheck;

final class ConfirmedDeletionTest extends DbTestCase
{
    private const string STATUS_URL = 'https://mastodon.example/@alice/120';
    private const string URL_HASH = 'hash-120';

    private FakeHomeServerCheck $homeServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeServer = new FakeHomeServerCheck();
        $this->homeServer->confirmsDeletions();
    }

    public function testAHomeTimelinePostTakesOnlyTheOpenedCopy(): void
    {
        $opened = $this->postOn($this->feed('social:mastodon:1:home', SourceFormat::MASTODON_API));
        $sibling = $this->postOn($this->feed('social:mastodon:2:home', SourceFormat::MASTODON_API));
        $profileCopy = $this->postOn($this->feed('https://mastodon.example/@alice.rss', SourceFormat::XML));

        self::assertSame([false, true, true], $this->survivorsAfterRemoving($opened, [$sibling, $profileCopy]));
    }

    public function testAProfileFeedPostTakesEveryCopy(): void
    {
        $opened = $this->postOn($this->feed('https://mastodon.example/@alice.rss', SourceFormat::XML));
        $homeCopy = $this->postOn($this->feed('social:mastodon:1:home', SourceFormat::MASTODON_API));

        self::assertSame([false, false], $this->survivorsAfterRemoving($opened, [$homeCopy]));
    }

    /**
     * @param list<Entry> $siblings
     *
     * @return list<bool> whether the opened entry, then each sibling, is still stored
     */
    private function survivorsAfterRemoving(Entry $opened, array $siblings): array
    {
        $ids = array_map(static fn (Entry $entry): int => $entry->requireId(), [$opened, ...$siblings]);
        try {
            ConfirmedDeletions::askingOf($this->entityManager, $this->homeServer)->removeIfConfirmed($opened);
            self::fail('A post its home server confirms deleted must be announced removed.');
        } catch (SocialPostRemovedException) {
            $this->entityManager->clear();
        }

        return array_map(fn (int $id): bool => null !== $this->entityManager->find(Entry::class, $id), $ids);
    }

    private function feed(string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function postOn(Feed $feed): Entry
    {
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');
        $entry = new Entry($feed, self::STATUS_URL, self::STATUS_URL, 'Post', $now, $now, self::URL_HASH);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
