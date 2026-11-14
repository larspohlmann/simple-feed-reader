<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class TrendingRunTest extends TestCase
{
    private const float TWICE = 6.2159;
    private const string CLIPPED_TITLE = 'Storm surge hits Hamb…';
    private const string SUMMARY = '<p>Storm surge hits Hamburg harbour</p>';

    public function testADerivedTitleRepeatedAtTheStartOfTheBodyAddsNothingFromTheRepeat(): void
    {
        self::assertEqualsWithDelta(1.5 * self::TWICE, $this->weightSharedWith(
            new WindowEntryModel(2, 20, self::CLIPPED_TITLE, true, self::SUMMARY, self::later(), null, null),
        ), 0.0001);
    }

    public function testAnArticleWhoseSummaryStartsWithItsTitleKeepsThoseBodyStems(): void
    {
        self::assertEqualsWithDelta(2.0 * self::TWICE, $this->weightSharedWith(
            new WindowEntryModel(2, 20, self::CLIPPED_TITLE, false, self::SUMMARY, self::later(), null, null),
        ), 0.0001);
    }

    private function weightSharedWith(WindowEntryModel $later): float
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'Storm Hamburg harbour', '2031-05-14 09:00:00'), []);
        $run->add($later, []);

        return $run->headlines->sharedWeightsWithEarlier($later->id)[1];
    }

    private static function later(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2031-05-14 10:00:00', new \DateTimeZone('UTC'));
    }
}
