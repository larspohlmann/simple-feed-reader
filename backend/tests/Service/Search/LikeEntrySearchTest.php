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
 * BEHAVIOUR only, so it builds the implementation directly.
 *
 * Nothing here guards the DI binding, and nothing can: Symfony autowires
 * EntrySearchInterface because exactly one service implements it, so removing
 * the explicit alias in services.yaml changes nothing until a second
 * implementation exists. The alias is kept because it states the binding and
 * makes that second implementation a one-line change rather than an ambiguity
 * error.
 */
final class LikeEntrySearchTest extends DbTestCase
{
    public function testFindsASubscribedEntryByTerm(): void
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

        $result = $search->search(new EntrySearchQuery(
            userId: $user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertCount(1, $result->rows);
        self::assertSame('Angular 20 ships', $result->rows[0]->entry->getTitle());
        self::assertSame([], $result->matchedWords);
        // The database path's matchCount is the row count — nothing removes
        // rows after the query runs, unlike the indexed search's hydration
        // step — so EntrySearchResult must default it from count($rows)
        // rather than the caller having to say so.
        self::assertSame(1, $result->matchCount);
    }
}
