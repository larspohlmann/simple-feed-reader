<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use App\Service\Recommendation\RecommendationPickParser;
use PHPUnit\Framework\TestCase;

final class RecommendationPickParserTest extends TestCase
{
    private RecommendationPickParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RecommendationPickParser(new ModelReplyJsonDecoder());
    }

    public function testCleanReplyIsUsableWithOrderAndReasonsPreserved(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 3, 'score' => 90, 'reason' => 'Third'],
                ['id' => 1, 'score' => 80, 'reason' => 'First'],
                ['id' => 2, 'score' => 70, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([3, 1, 2], array_map(static fn ($pick) => $pick->entryId, $result->picks));
        self::assertSame(['Third', 'First', 'Second'], array_map(static fn ($pick) => $pick->reason, $result->picks));
    }

    public function testInvalidIdsAndDuplicatesAreDroppedButValidRemainderKept(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 90, 'reason' => 'First'],
                ['id' => 99, 'score' => 80, 'reason' => 'Not a candidate'],
                ['id' => 1, 'score' => 70, 'reason' => 'Duplicate of first'],
                ['id' => 2, 'score' => 60, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([1, 2], array_map(static fn ($pick) => $pick->entryId, $result->picks));
        self::assertSame('First', $result->picks[0]->reason);
    }

    public function testAllIdsInvalidIsUnusable(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 99, 'reason' => 'Not a candidate'],
                ['id' => 100, 'reason' => 'Also not a candidate'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2, 3]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->picks);
    }

    public function testEmptyRecommendationsArrayIsUnusable(): void
    {
        $content = self::encode(['recommendations' => []]);

        $result = $this->parser->parse($content, [1, 2, 3]);

        self::assertFalse($result->usable);
    }

    public function testRecommendationsNotAListIsUnusable(): void
    {
        $content = self::encode(['recommendations' => 'x']);

        $result = $this->parser->parse($content, [1, 2, 3]);

        self::assertFalse($result->usable);
    }

    public function testUnparseableJsonIsUnusable(): void
    {
        $result = $this->parser->parse('not json', [1, 2, 3]);

        self::assertFalse($result->usable);
    }

    public function testMissingRecommendationsKeyIsUnusable(): void
    {
        $content = self::encode(['other' => []]);

        $result = $this->parser->parse($content, [1, 2, 3]);

        self::assertFalse($result->usable);
    }


    /**
     * The fence handling itself belongs to ModelReplyJsonDecoder and is
     * covered exhaustively there; this only pins that the parser delegates
     * to it rather than decoding the reply itself.
     */
    public function testAFencedReplyIsDecodedThroughTheSharedDecoder(): void
    {
        $payload = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 90, 'reason' => 'First'],
            ],
        ]);

        $result = $this->parser->parse("```json\n{$payload}\n```", [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testNumericStringIdIsAcceptedAsInt(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => '42', 'score' => 90, 'reason' => 'From a lenient gateway'],
            ],
        ]);

        $result = $this->parser->parse($content, [42]);

        self::assertTrue($result->usable);
        self::assertSame([42], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testMissingReasonSalvagesAsEmptyString(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 90],
            ],
        ]);

        $result = $this->parser->parse($content, [1]);

        self::assertTrue($result->usable);
        self::assertSame('', $result->picks[0]->reason);
    }

    public function testBlankReasonSalvagesAsEmptyString(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 90, 'reason' => '   '],
            ],
        ]);

        $result = $this->parser->parse($content, [1]);

        self::assertTrue($result->usable);
        self::assertSame('', $result->picks[0]->reason);
    }

    public function testNonStringReasonSalvagesAsEmptyString(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 90, 'reason' => 42],
            ],
        ]);

        $result = $this->parser->parse($content, [1]);

        self::assertTrue($result->usable);
        self::assertSame('', $result->picks[0]->reason);
    }

    public function testNonArrayEntryInRecommendationsIsSkipped(): void
    {
        $content = self::encode([
            'recommendations' => [
                'not-an-object',
                ['id' => 1, 'score' => 90, 'reason' => 'First'],
            ],
        ]);

        $result = $this->parser->parse($content, [1]);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }




    public function testIdGivenAsAnArrayIsRejectedRatherThanCrashing(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => [1, 2], 'score' => 90, 'reason' => 'Wrong shape'],
                ['id' => 1, 'score' => 80, 'reason' => 'First'],
            ],
        ]);

        $result = $this->parser->parse($content, [1]);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testScoresAreSalvagedAndPreserved(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 90, 'reason' => 'First'],
                ['id' => 2, 'score' => 15, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2]);

        self::assertTrue($result->usable);
        self::assertSame([90, 15], array_map(static fn ($pick) => $pick->score, $result->picks));
    }

    /**
     * Both sides of the halfway point are pinned on purpose: a fractional
     * score that only ever sat at .5 reads the same whether the parser
     * rounds, floors or ceils it.
     */
    public function testFloatAndNumericStringScoresRoundToInt(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 87.5, 'reason' => 'Float rounding up'],
                ['id' => 2, 'score' => 87.4, 'reason' => 'Float rounding down'],
                ['id' => 3, 'score' => '73', 'reason' => 'String'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([88, 87, 73], array_map(static fn ($pick) => $pick->score, $result->picks));
    }

    public function testOutOfRangeScoresAreClampedIntoTheScale(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 150, 'reason' => 'Too high'],
                ['id' => 2, 'score' => -5, 'reason' => 'Too low'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2]);

        self::assertTrue($result->usable);
        self::assertSame([100, 0], array_map(static fn ($pick) => $pick->score, $result->picks));
    }

    public function testAPickWithoutAScoreIsDiscarded(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'Scoreless'],
                ['id' => 2, 'score' => 40, 'reason' => 'Scored'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2]);

        self::assertTrue($result->usable);
        self::assertSame([2], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testANonNumericScoreDiscardsThePick(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 'high', 'reason' => 'Wordy'],
            ],
        ]);

        $result = $this->parser->parse($content, [1]);

        self::assertFalse($result->usable);
    }

    public function testAReplyInTheOldScorelessShapeIsUnusable(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
                ['id' => 2, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2]);

        self::assertFalse($result->usable);
    }

    /** @param array<mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }
}
