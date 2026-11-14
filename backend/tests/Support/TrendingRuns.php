<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Pass\HeadlineWindow;
use App\Service\Curation\Trending\Pass\TrendingRun;

final class TrendingRuns
{
    public const string VOLCANO_HEADLINE = 'Vulkan Ausbruch Island';
    public const string VOLCANO_REPLY_HEADLINE = 'Island: Vulkan Ausbruch heute';

    public static function of(TrendingWindow $window): TrendingRun
    {
        return new TrendingRun($window, new HeadlineWindow(TrendingWiring::filler(), TrendingWiring::equivalents()));
    }

    public static function entry(int $id, int $feedId, string $title, string $effectiveDate): WindowEntryModel
    {
        return new WindowEntryModel(
            $id,
            $feedId,
            null,
            $title,
            false,
            null,
            new \DateTimeImmutable($effectiveDate, new \DateTimeZone('UTC')),
            null,
            null,
        );
    }

    private function __construct()
    {
    }
}
