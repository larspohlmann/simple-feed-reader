<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Refresh\IncomingEntries;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\DeletedPostTombstones;
use App\Tests\DbTestCase;

final class IncomingEntriesTest extends DbTestCase
{
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new Feed('https://mastodon.example/@alice.rss');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testAPageWithNothingBuriedInItsFeedIsHandedBackAsItCame(): void
    {
        $incoming = self::incoming(['https://mastodon.example/@alice/1']);

        self::assertSame($incoming, $this->incomingEntries()->withoutDeletedPosts($this->feed, $incoming));
    }

    public function testTheBuriedPostsLeaveAListOfTheRest(): void
    {
        $this->tombstones()->bury($this->feed->requireId(), [EntryGuid::hashOf('https://mastodon.example/@alice/1')]);
        $incoming = self::incoming(['https://mastodon.example/@alice/1', 'https://mastodon.example/@alice/2']);

        $stored = $this->incomingEntries()->withoutDeletedPosts($this->feed, $incoming);

        self::assertSame([$incoming->parsed->entries[1]], $stored->parsed->entries);
    }

    /** @param list<string> $guids */
    private static function incoming(array $guids): IncomingFeedModel
    {
        return IncomingFeedModel::parsed(new ParsedFeedModel('Alice', null, null, null, array_map(
            static fn (string $guid): ParsedEntryModel => new ParsedEntryModel(
                $guid,
                $guid,
                Headline::fromFeed('Post'),
                null,
                null,
                '<p>Post</p>',
                null,
            ),
            $guids,
        )));
    }

    private function incomingEntries(): IncomingEntries
    {
        $incomingEntries = self::getContainer()->get(IncomingEntries::class);
        self::assertInstanceOf(IncomingEntries::class, $incomingEntries);

        return $incomingEntries;
    }

    private function tombstones(): DeletedPostTombstones
    {
        $tombstones = self::getContainer()->get(DeletedPostTombstones::class);
        self::assertInstanceOf(DeletedPostTombstones::class, $tombstones);

        return $tombstones;
    }
}
