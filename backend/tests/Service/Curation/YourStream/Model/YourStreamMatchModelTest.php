<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream\Model;

use App\Service\Curation\YourStream\Model\StreamScoreModel;
use App\Service\Curation\YourStream\Model\YourStreamMatchModel;
use App\Service\Curation\YourStream\Model\YourStreamSweepReportModel;
use PHPUnit\Framework\TestCase;

final class YourStreamMatchModelTest extends TestCase
{
    public function testTheStemsAreJoinedBySpaces(): void
    {
        $match = new YourStreamMatchModel(1, 2, new StreamScoreModel(2.0, ['solar', 'grid']));

        self::assertSame('solar grid', $match->formatMatchedStems());
    }

    public function testTheStemsAreCutAtTwoHundredFiftyFiveCharacters(): void
    {
        $match = new YourStreamMatchModel(1, 2, new StreamScoreModel(2.0, array_fill(0, 100, 'äbcdef')));

        self::assertSame(255, mb_strlen($match->formatMatchedStems()));
    }

    public function testNoReportIsNotCaughtUp(): void
    {
        self::assertSame(
            ['usersSwept' => 0, 'entriesScanned' => 0, 'matchesInserted' => 0, 'caughtUp' => false],
            YourStreamSweepReportModel::none()->toLogContext(),
        );
    }
}
