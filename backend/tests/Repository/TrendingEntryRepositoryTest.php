<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Repository\TrendingEntryRepository;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class TrendingEntryRepositoryTest extends DbTestCase
{
    public function testStreamsTheWindowsEntriesInIdOrderWithTheirFirstRootLinkCard(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('stream');
        $tooOld = $fixtures->entry($feed, 'Too old', '2031-05-13 11:59:59');
        $first = $fixtures->article($feed, 'First', '2031-05-13 12:00:00', 'https://news.example.com/first');
        $second = $fixtures->entry($feed, 'Second', '2031-05-14 09:00:00');
        $second->setSummary('<p>Body</p>');
        $fixtures->flush();
        $this->entityManager->persist(new LinkCard($second, 1, self::card('https://later.example.com/b')));
        $this->entityManager->persist(new LinkCard($second, 0, self::card('https://shared.example.com/a')));
        $this->entityManager->flush();

        $rows = iterator_to_array(
            (new TrendingEntryRepository($this->entityManager))->windowEntries(
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            ),
            false,
        );

        self::assertSame(
            [$first->requireId(), $second->requireId()],
            array_map(static fn (WindowEntryModel $row): int => $row->id, $rows),
        );
        self::assertNotContains(
            $tooOld->requireId(),
            array_map(static fn (WindowEntryModel $row): int => $row->id, $rows),
        );
        self::assertSame($feed->requireId(), $rows[0]->feedId);
        self::assertSame($first->getUrlHash(), $rows[0]->urlHash);
        self::assertNull($rows[0]->linkCardUrl);
        self::assertSame('https://shared.example.com/a', $rows[1]->linkCardUrl);
        self::assertSame('<p>Body</p>', $rows[1]->summary);
        self::assertSame('2031-05-14 09:00:00', $rows[1]->effectiveDate->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $rows[1]->effectiveDate->getTimezone()->getName());
    }

    public function testStreamsMoreThanOneChunkWithoutRepeatingOrSkipping(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('chunks');
        $expected = [];
        for ($index = 0; $index <= 1_000; ++$index) {
            $expected[] = $fixtures->entry($feed, 'In ' . $index, '2031-05-14 09:00:00');
            if ($index === 500) {
                $fixtures->flush();
                $fixtures->entry($feed, 'Out', '2031-05-01 09:00:00');
                $fixtures->flush();
            }
        }
        $fixtures->flush();
        $expectedIds = array_map(static fn (Entry $entry): int => $entry->requireId(), $expected);

        $streamedIds = [];
        $rows = (new TrendingEntryRepository($this->entityManager))
            ->windowEntries(new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')));
        foreach ($rows as $row) {
            $streamedIds[] = $row->id;
            if (\count($streamedIds) > 3_000) {
                break;
            }
        }

        self::assertSame($expectedIds, $streamedIds);
    }

    private static function card(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Card', null, null, 'example.com');
    }
}
