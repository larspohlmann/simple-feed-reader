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

final class EntryUnreadMatchIdsTest extends DbTestCase
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
            $state->setIsHidden(true);
            $this->em->persist($state);
        }
        $this->em->flush();

        return $entry;
    }

    /** @return list<int> */
    private function matchIds(string $input): array
    {
        return $this->repo()->unreadMatchIdsForUser(new EntrySearchQuery(
            userId: (int) $this->user->getId(),
            terms: SearchTerms::fromInput($input),
        ));
    }

    public function testReturnsOnlyUnreadMatchingIds(): void
    {
        $policy = $this->entry('a', 'Climate policy update', false); // unread match
        $recap = $this->entry('b', 'Climate summit recap', false);   // unread match
        $this->entry('c', 'Climate deal signed', true);              // read match -> excluded
        $this->entry('d', 'Unrelated cooking post', false);          // no match

        $ids = $this->matchIds('climate');

        sort($ids);
        $expected = [(int) $policy->getId(), (int) $recap->getId()];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function testWholeWordMatchIsStricterThanSubstring(): void
    {
        $revival = $this->entry('e', 'A punk revival', false); // whole word "punk"
        $this->entry('f', 'Steampunk gadgets', false);         // substring only

        self::assertCount(2, $this->matchIds('punk'));   // substring: both
        // trailing space = whole word: only "A punk revival"
        self::assertSame([(int) $revival->getId()], $this->matchIds('punk '));
    }
}
