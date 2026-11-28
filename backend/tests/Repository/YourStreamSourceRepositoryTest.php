<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\YourStreamSourceRepository;
use App\Tests\DbTestCase;

final class YourStreamSourceRepositoryTest extends DbTestCase
{
    private YourStreamSourceRepository $sources;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new Feed('https://example.com/source.xml');
        $this->entityManager->persist($this->feed);
        $sources = self::getContainer()->get(YourStreamSourceRepository::class);
        self::assertInstanceOf(YourStreamSourceRepository::class, $sources);
        $this->sources = $sources;
    }

    public function testTheFirstEntryIdSinceIsTheLowestIdAtOrAfterTheInstant(): void
    {
        $this->entry('old', '2026-10-01 00:00:00');
        $boundary = $this->entry('boundary', '2026-10-28 00:00:00');
        $this->entry('recent', '2026-11-20 00:00:00');
        $lateOldDate = $this->entry('older-id-later', '2026-10-15 00:00:00');
        $this->entityManager->flush();

        $first = $this->sources->findFirstEntryIdSince(new \DateTimeImmutable('2026-10-28 00:00:00'));

        self::assertSame($boundary->requireId(), $first);
        self::assertLessThan($lateOldDate->requireId(), $first);
    }

    public function testNoEntryAtOrAfterTheInstantIsNull(): void
    {
        $this->entry('old', '2026-10-01 00:00:00');
        $this->entityManager->flush();

        self::assertNull($this->sources->findFirstEntryIdSince(new \DateTimeImmutable('2026-11-01 00:00:00')));
    }

    private function entry(string $guid, string $effectiveAt): Entry
    {
        $date = new \DateTimeImmutable($effectiveAt);
        $entry = new Entry($this->feed, $guid, null, $guid, $date, $date);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
