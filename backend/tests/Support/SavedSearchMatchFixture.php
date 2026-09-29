<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A real saved search backed by the membership table, for tests that need DigestEntryFinder to find a match.
 * SavedSearchEntryRepository is final, so it cannot be doubled: these rows are the only way to feed it one.
 */
final readonly class SavedSearchMatchFixture
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function search(User $user, string $term): SavedSearch
    {
        $search = new SavedSearch($user, $term, false);
        $this->entityManager->persist($search);
        $this->entityManager->flush();

        return $search;
    }

    public function member(User $user, SavedSearch $search, \DateTimeImmutable $effectiveDate): Entry
    {
        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'guid-' . uniqid('', true),
            'https://example.com/' . uniqid('', true),
            'Title',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $effectiveDate,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->persist(
            new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00Z')),
        );
        $this->entityManager->flush();

        return $entry;
    }

    /** One search with one unread member, for a test that just needs a match. */
    public function oneMatch(User $user, string $term, \DateTimeImmutable $effectiveDate): SavedSearch
    {
        $search = $this->search($user, $term);
        $this->member($user, $search, $effectiveDate);

        return $search;
    }
}
