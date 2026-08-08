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
                ['id' => 3, 'reason' => 'Third'],
                ['id' => 1, 'reason' => 'First'],
                ['id' => 2, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2, 3], 10);

        self::assertTrue($result->usable);
        self::assertSame([3, 1, 2], array_map(static fn ($pick) => $pick->entryId, $result->picks));
        self::assertSame(['Third', 'First', 'Second'], array_map(static fn ($pick) => $pick->reason, $result->picks));
    }

    public function testInvalidIdsAndDuplicatesAreDroppedButValidRemainderKept(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
                ['id' => 99, 'reason' => 'Not a candidate'],
                ['id' => 1, 'reason' => 'Duplicate of first'],
                ['id' => 2, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2, 3], 10);

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

        $result = $this->parser->parse($content, [1, 2, 3], 10);

        self::assertFalse($result->usable);
        self::assertSame([], $result->picks);
    }

    public function testEmptyRecommendationsArrayIsUnusable(): void
    {
        $content = self::encode(['recommendations' => []]);

        $result = $this->parser->parse($content, [1, 2, 3], 10);

        self::assertFalse($result->usable);
    }

    public function testRecommendationsNotAListIsUnusable(): void
    {
        $content = self::encode(['recommendations' => 'x']);

        $result = $this->parser->parse($content, [1, 2, 3], 10);

        self::assertFalse($result->usable);
    }

    public function testUnparseableJsonIsUnusable(): void
    {
        $result = $this->parser->parse('not json', [1, 2, 3], 10);

        self::assertFalse($result->usable);
    }

    public function testMissingRecommendationsKeyIsUnusable(): void
    {
        $content = self::encode(['other' => []]);

        $result = $this->parser->parse($content, [1, 2, 3], 10);

        self::assertFalse($result->usable);
    }

    public function testFencedJsonReplyParses(): void
    {
        $payload = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
            ],
        ]);
        $content = "```json\n{$payload}\n```";

        $result = $this->parser->parse($content, [1, 2, 3], 10);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testPlainFencedReplyWithoutLanguageTagParses(): void
    {
        $payload = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
            ],
        ]);
        $content = "```\n{$payload}\n```";

        $result = $this->parser->parse($content, [1, 2, 3], 10);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testPicksBeyondLimitAreDropped(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
                ['id' => 2, 'reason' => 'Second'],
                ['id' => 3, 'reason' => 'Third'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2, 3], 2);

        self::assertTrue($result->usable);
        self::assertSame([1, 2], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testNumericStringIdIsAcceptedAsInt(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => '42', 'reason' => 'From a lenient gateway'],
            ],
        ]);

        $result = $this->parser->parse($content, [42], 10);

        self::assertTrue($result->usable);
        self::assertSame([42], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testMissingReasonSalvagesAsEmptyString(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1],
            ],
        ]);

        $result = $this->parser->parse($content, [1], 10);

        self::assertTrue($result->usable);
        self::assertSame('', $result->picks[0]->reason);
    }

    public function testBlankReasonSalvagesAsEmptyString(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => '   '],
            ],
        ]);

        $result = $this->parser->parse($content, [1], 10);

        self::assertTrue($result->usable);
        self::assertSame('', $result->picks[0]->reason);
    }

    public function testNonStringReasonSalvagesAsEmptyString(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 42],
            ],
        ]);

        $result = $this->parser->parse($content, [1], 10);

        self::assertTrue($result->usable);
        self::assertSame('', $result->picks[0]->reason);
    }

    public function testNonArrayEntryInRecommendationsIsSkipped(): void
    {
        $content = self::encode([
            'recommendations' => [
                'not-an-object',
                ['id' => 1, 'reason' => 'First'],
            ],
        ]);

        $result = $this->parser->parse($content, [1], 10);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testSurroundingWhitespaceIsTrimmedBeforeFenceDetection(): void
    {
        $payload = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
            ],
        ]);
        $content = "  \n```json\n{$payload}\n```\n  ";

        $result = $this->parser->parse($content, [1], 10);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testClosingFenceWithoutAnOpeningFenceIsNotStripped(): void
    {
        $payload = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
            ],
        ]);
        $content = $payload . '```';

        $result = $this->parser->parse($content, [1], 10);

        self::assertFalse($result->usable);
    }

    public function testFenceClosingImmediatelyAfterTheJsonWithNoSeparatorIsStripped(): void
    {
        $payload = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
            ],
        ]);
        $content = "```json\n{$payload}```";

        $result = $this->parser->parse($content, [1], 10);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testIdGivenAsAnArrayIsRejectedRatherThanCrashing(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => [1, 2], 'reason' => 'Wrong shape'],
                ['id' => 1, 'reason' => 'First'],
            ],
        ]);

        $result = $this->parser->parse($content, [1], 10);

        self::assertTrue($result->usable);
        self::assertSame([1], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    /** @param array<mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }
}
