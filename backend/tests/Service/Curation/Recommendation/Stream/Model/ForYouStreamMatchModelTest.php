<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream\Model;

use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamMatchModel;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;
use App\Service\Curation\Recommendation\Stream\Model\StreamScoreModel;
use PHPUnit\Framework\TestCase;

final class ForYouStreamMatchModelTest extends TestCase
{
    public function testTheStemsAreJoinedBySpaces(): void
    {
        $match = new ForYouStreamMatchModel(1, 2, new StreamScoreModel(2.0, ['solar', 'grid']));

        self::assertSame('solar grid', $match->formatMatchedStems());
    }

    public function testTheStemsAreCutAtTwoHundredFiftyFiveCharacters(): void
    {
        $match = new ForYouStreamMatchModel(1, 2, new StreamScoreModel(2.0, array_fill(0, 100, 'äbcdef')));

        self::assertSame(255, mb_strlen($match->formatMatchedStems()));
    }

    public function testNoReportIsNotCaughtUp(): void
    {
        self::assertSame(
            ['usersSwept' => 0, 'entriesScanned' => 0, 'matchesInserted' => 0, 'caughtUp' => false],
            ForYouStreamSweepReportModel::none()->toLogContext(),
        );
    }
}
