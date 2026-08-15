<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\LikeEntrySearch;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * The seam an Elasticsearch implementation would replace. This test covers the
 * BEHAVIOUR only, so it builds the implementation directly. Proving the DI
 * alias is the endpoint test's job: a container fetch here would need the alias
 * made public in the test environment, and that override replaces the
 * production entry — so this test would pass with the production alias deleted,
 * which is the one failure it would appear to be guarding.
 */
final class LikeEntrySearchTest extends DbTestCase
{
    public function testFindsASubscribedEntryThroughTheInterface(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'guid',
            'https://example.com/guid',
            'Angular 20 ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        $repository = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repository);
        $search = new LikeEntrySearch($repository);

        $rows = $search->search(new EntrySearchQuery(
            userId: $user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertCount(1, $rows);
        self::assertSame('Angular 20 ships', $rows[0]->entry->getTitle());
    }
}
