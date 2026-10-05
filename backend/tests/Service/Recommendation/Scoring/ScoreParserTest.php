<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\ScoreParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoreParserTest extends TestCase
{
    public function testEveryCandidateIsAWinnerWithItsScoreAndNoReasonInTheBatchsOrder(): void
    {
        $result = (new ScoreParser())->parse($this->reply([9 => 0.31, 4 => 0.875]), [4, 9]);

        self::assertTrue($result->usable);
        self::assertSame(
            [['id' => 4, 'score' => 875, 'reason' => ''], ['id' => 9, 'score' => 310, 'reason' => '']],
            $result->winners,
        );
    }

    public function testAReplyMissingACandidatesScoreIsUnusable(): void
    {
        $result = (new ScoreParser())->parse($this->reply([4 => 0.875]), [4, 9]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->winners);
    }

    /** @return iterable<string, array{float}> */
    public static function impossibleScores(): iterable
    {
        yield 'above one' => [1.2];
        yield 'below zero' => [-0.1];
        yield 'not a number' => [\NAN];
        yield 'infinite' => [\INF];
    }

    #[DataProvider('impossibleScores')]
    public function testAScoreThatIsNoProbabilityMakesTheReplyUnusable(float $score): void
    {
        $result = (new ScoreParser())->parse($this->reply([4 => 0.875, 9 => $score]), [4, 9]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->winners);
    }

    public function testTheBoundsOfAProbabilityAreUsable(): void
    {
        $result = (new ScoreParser())->parse($this->reply([4 => 0.0, 9 => 1.0]), [4, 9]);

        self::assertTrue($result->usable);
        self::assertSame(
            [['id' => 4, 'score' => 0, 'reason' => ''], ['id' => 9, 'score' => 1000, 'reason' => '']],
            $result->winners,
        );
    }

    /** @param array<int, float> $scores */
    private function reply(array $scores): ScoringReplyModel
    {
        return new ScoringReplyModel('{}', $scores, new ProviderCallReceiptModel(null, null, null));
    }
}
