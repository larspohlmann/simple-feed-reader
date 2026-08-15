<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\EntrySearchInterface;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * The seam an Elasticsearch implementation would replace. The test resolves it
 * from the container by its INTERFACE on purpose: a missing alias in
 * services.yaml is exactly the failure this must catch, and autowiring a plain
 * application interface is not automatic.
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

        $search = self::getContainer()->get(EntrySearchInterface::class);
        self::assertInstanceOf(EntrySearchInterface::class, $search);

        $rows = $search->search(new EntrySearchQuery(
            userId: $user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertCount(1, $rows);
        self::assertSame('Angular 20 ships', $rows[0]->entry->getTitle());
    }
}
