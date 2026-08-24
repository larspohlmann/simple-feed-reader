<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

final class EntryUnreadMatchCountTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('counter@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    private function entry(string $guid, string $title, bool $read): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        if ($read) {
            $state = new EntryState($this->user, $entry);
            $state->setIsRead(true);
            $this->em->persist($state);
        }
        $this->em->flush();

        return $entry;
    }

    private function countMatches(string $input): int
    {
        return $this->repo()->countUnreadMatchesForUser(new EntrySearchQuery(
            userId: (int) $this->user->getId(),
            terms: SearchTerms::fromInput($input),
        ));
    }

    public function testCountsOnlyUnreadMatches(): void
    {
        $this->entry('a', 'Climate policy update', false); // unread match
        $this->entry('b', 'Climate summit recap', false);  // unread match
        $this->entry('c', 'Climate deal signed', true);     // read match -> excluded
        $this->entry('d', 'Unrelated cooking post', false); // no match

        self::assertSame(2, $this->countMatches('climate'));
    }

    public function testWholeWordCountIsStricterThanSubstring(): void
    {
        $this->entry('e', 'A punk revival', false);  // whole word "punk"
        $this->entry('f', 'Steampunk gadgets', false); // substring only

        self::assertSame(2, $this->countMatches('punk'));   // substring: both
        self::assertSame(1, $this->countMatches('punk '));  // trailing space = whole word: only "A punk revival"
    }
}
