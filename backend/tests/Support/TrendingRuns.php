<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\FeedLanguage;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Pass\HeadlineWindow;
use App\Service\Curation\Trending\Pass\TrendingRun;

final class TrendingRuns
{
    public const string VOLCANO_HEADLINE = 'Vulkan Ausbruch Island';
    public const string VOLCANO_REPLY_HEADLINE = 'Island: Vulkan Ausbruch heute';

    public static function of(TrendingWindow $window): TrendingRun
    {
        return new TrendingRun(
            $window,
            new \DateTimeImmutable('2031-05-07 12:00:00', new \DateTimeZone('UTC')),
            new HeadlineWindow(TrendingWiring::filler(), StemmingWiring::equivalents()),
        );
    }

    /** @param list<string> $headlines one entry per headline, ids from 1, each in its own feed */
    public static function buildHeadlineRun(
        TrendingWindow $window,
        array $headlines,
        string $effectiveDate,
    ): TrendingRun {
        $run = self::of($window);
        foreach ($headlines as $index => $headline) {
            $run->add(self::entry($index + 1, $index + 1, $headline, $effectiveDate), [], null);
        }

        return $run;
    }

    public static function entry(int $id, int $feedId, string $title, string $effectiveDate): WindowEntryModel
    {
        return self::entryIn(null, $id, $feedId, $title, $effectiveDate);
    }

    public static function germanEntry(int $id, int $feedId, string $title, string $effectiveDate): WindowEntryModel
    {
        return self::entryIn(FeedLanguage::German, $id, $feedId, $title, $effectiveDate);
    }

    private static function entryIn(
        ?FeedLanguage $language,
        int $id,
        int $feedId,
        string $title,
        string $effectiveDate,
    ): WindowEntryModel {
        return new WindowEntryModel(
            $id,
            $feedId,
            $language,
            $title,
            false,
            null,
            new \DateTimeImmutable($effectiveDate, new \DateTimeZone('UTC')),
            new EntryLinksModel(null, null, null),
        );
    }

    private function __construct()
    {
    }
}
