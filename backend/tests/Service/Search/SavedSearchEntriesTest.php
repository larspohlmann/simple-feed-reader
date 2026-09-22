<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SavedSearchListQuery;
use App\Service\Search\SavedSearchEntries;
use App\Tests\DbTestCase;

final class SavedSearchEntriesTest extends DbTestCase
{
    public function testAnswersEveryRowAnyOfTheGivenSearchesMatches(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($user);
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $climate = new SavedSearch($user, 'climate', false);
        $rocket = new SavedSearch($user, 'rocket', false);
        $this->em->persist($climate);
        $this->em->persist($rocket);
        $entry = new Entry(
            $feed,
            'a',
            'https://example.com/a',
            'Climate rocket',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();
        $matchedAt = new \DateTimeImmutable('2026-09-22T10:00:00');
        $this->em->persist(new SavedSearchEntry($climate, $entry, $matchedAt));
        $this->em->persist(new SavedSearchEntry($rocket, $entry, $matchedAt));
        $this->em->flush();

        $service = self::getContainer()->get(SavedSearchEntries::class);
        self::assertInstanceOf(SavedSearchEntries::class, $service);
        $result = $service->list(new SavedSearchListQuery(
            (int) $user->getId(),
            [(int) $rocket->getId(), (int) $climate->getId()],
        ));

        self::assertCount(1, $result->rows);
        self::assertSame($entry->getId(), $result->rows[0]->entry->getId());
    }
}
