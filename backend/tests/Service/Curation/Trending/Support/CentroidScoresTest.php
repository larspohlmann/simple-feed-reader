<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Pass\HeadlineWindow;
use App\Service\Curation\Trending\Support\CentroidScores;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\TestCase;

final class CentroidScoresTest extends TestCase
{
    private const int OUTSIDER = 1;
    private const int FIRST = 2;
    private const int SECOND = 3;
    private const int THIRD = 4;

    public function testTheMemberSharingMostWithTheOthersScoresHighest(): void
    {
        $headlines = new HeadlineWindow(TrendingWiring::filler(), TrendingWiring::equivalents());
        $this->add($headlines, self::OUTSIDER, 'Hafenstreik Hamburg Container');
        $this->add($headlines, self::FIRST, 'Hafenstreik Hamburg Container');
        $this->add($headlines, self::SECOND, 'Hafenstreik Hamburg');
        $this->add($headlines, self::THIRD, 'Hafenstreik Container');

        $scores = CentroidScores::computeScores($headlines, [self::FIRST, self::SECOND, self::THIRD]);

        self::assertGreaterThan($scores[self::SECOND], $scores[self::FIRST]);
        self::assertGreaterThan($scores[self::THIRD], $scores[self::FIRST]);
        self::assertSame([self::FIRST, self::SECOND, self::THIRD], array_keys($scores));
    }

    private function add(HeadlineWindow $headlines, int $entryId, string $headline): void
    {
        $headlines->add(
            new WindowEntryModel(
                $entryId,
                $entryId * 10,
                FeedLanguage::German,
                $headline,
                false,
                null,
                new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC')),
                new EntryLinksModel(null, null, null),
            ),
            '',
            null,
        );
    }
}
