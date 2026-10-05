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
        $result = (new ScoreParser())->parse($this->reply(['entry-9' => 0.31, 'entry-4' => 0.875]), [4, 9]);

        self::assertTrue($result->usable);
        self::assertSame(
            [['id' => 4, 'score' => 875, 'reason' => ''], ['id' => 9, 'score' => 310, 'reason' => '']],
            $result->winners,
        );
    }

    public function testAReplyMissingACandidatesNoulIsUnusable(): void
    {
        $result = (new ScoreParser())->parse($this->reply(['entry-4' => 0.875]), [4, 9]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->winners);
    }

    /** @return iterable<string, array{float}> */
    public static function impossibleNouls(): iterable
    {
        yield 'above one' => [1.2];
        yield 'below zero' => [-0.1];
        yield 'not a number' => [\NAN];
        yield 'infinite' => [\INF];
    }

    #[DataProvider('impossibleNouls')]
    public function testANoulThatIsNoProbabilityMakesTheReplyUnusable(float $noul): void
    {
        $result = (new ScoreParser())->parse($this->reply(['entry-4' => 0.875, 'entry-9' => $noul]), [4, 9]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->winners);
    }

    public function testTheBoundsOfAProbabilityAreUsable(): void
    {
        $result = (new ScoreParser())->parse($this->reply(['entry-4' => 0.0, 'entry-9' => 1.0]), [4, 9]);

        self::assertTrue($result->usable);
        self::assertSame(
            [['id' => 4, 'score' => 0, 'reason' => ''], ['id' => 9, 'score' => 1000, 'reason' => '']],
            $result->winners,
        );
    }

    /** @param array<string, float> $nouls */
    private function reply(array $nouls): ScoringReplyModel
    {
        return new ScoringReplyModel('{}', $nouls, new ProviderCallReceiptModel(null, null, null));
    }
}
