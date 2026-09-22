<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SavedSearchEntryTest extends TestCase
{
    public function testCarriesTheSearchTheEntryAndWhenItMatched(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $search = new SavedSearch($user, 'climate', false);
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid-1',
            'https://example.com/1',
            'Climate report',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $matchedAt = new \DateTimeImmutable('2026-09-22T10:00:00');

        $membership = new SavedSearchEntry($search, $entry, $matchedAt);

        self::assertSame($search, $membership->getSavedSearch());
        self::assertSame($entry, $membership->getEntry());
        self::assertSame($matchedAt, $membership->getMatchedAt());
    }
}
