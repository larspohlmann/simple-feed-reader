<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use App\Service\Recommendation\RecommendationDuplicateParser;
use PHPUnit\Framework\TestCase;

final class RecommendationDuplicateParserTest extends TestCase
{
    private RecommendationDuplicateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RecommendationDuplicateParser(new ModelReplyJsonDecoder());
    }

    public function testValidDuplicateIdsAreKept(): void
    {
        $result = $this->parser->parse('{"duplicates": [2, 3]}', [1, 2, 3, 4]);

        self::assertTrue($result->usable);
        self::assertSame([2, 3], $result->duplicateIds);
    }

    public function testAnEmptyDuplicatesArrayIsUsable(): void
    {
        $result = $this->parser->parse('{"duplicates": []}', [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([], $result->duplicateIds);
    }

    public function testIdsNotShownToTheModelAreIgnored(): void
    {
        $result = $this->parser->parse('{"duplicates": [2, 99]}', [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([2], $result->duplicateIds);
    }

    /** A string id alone, so the plain int cannot stand in for the branch. */
    public function testANumericStringIdIsAccepted(): void
    {
        $result = $this->parser->parse('{"duplicates": ["2"]}', [1, 2]);

        self::assertTrue($result->usable);
        self::assertSame([2], $result->duplicateIds);
    }

    public function testRepeatedIdsAreCollapsed(): void
    {
        $result = $this->parser->parse('{"duplicates": ["2", 2]}', [1, 2]);

        self::assertTrue($result->usable);
        self::assertSame([2], $result->duplicateIds);
    }

    public function testNonNumericEntriesAreIgnoredRatherThanCrashing(): void
    {
        $result = $this->parser->parse('{"duplicates": ["x", [3], 2]}', [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([2], $result->duplicateIds);
    }

    public function testAFencedReplyParses(): void
    {
        $result = $this->parser->parse("```json\n{\"duplicates\": [1]}\n```", [1, 2]);

        self::assertTrue($result->usable);
        self::assertSame([1], $result->duplicateIds);
    }

    public function testMissingDuplicatesKeyIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('{"other": []}', [1])->usable);
    }

    public function testANonArrayDuplicatesValueIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('{"duplicates": "none"}', [1])->usable);
    }

    public function testUnparseableJsonIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('not json', [1])->usable);
    }

    /** The production failure of #396: a well-formed reply that names nearly everything. */
    public function testAReplyNamingMoreThanHalfOfTheShownEntriesIsUnusable(): void
    {
        $shownIds = range(1, 100);

        $result = $this->parser->parse(
            '{"duplicates": [' . implode(',', range(2, 94)) . ']}',
            $shownIds,
        );

        self::assertFalse($result->usable);
        self::assertSame([], $result->duplicateIds);
    }

    public function testAReplyNamingExactlyHalfOfTheShownEntriesIsStillUsable(): void
    {
        $result = $this->parser->parse(
            '{"duplicates": [' . implode(',', range(1, 50)) . ']}',
            range(1, 100),
        );

        self::assertTrue($result->usable);
        self::assertCount(50, $result->duplicateIds);
    }

    /** One over the line, so the boundary above cannot pass by rejecting nothing. */
    public function testOneEntryOverHalfIsAlreadyUnusable(): void
    {
        self::assertFalse(
            $this->parser->parse('{"duplicates": [' . implode(',', range(1, 51)) . ']}', range(1, 100))->usable,
        );
    }

    /**
     * An odd-sized pool has no exact half, and "more than half of 99" is 50 —
     * the arithmetic must round the bound down, not up.
     */
    public function testHalfOfAnOddSizedPoolRoundsAgainstTheReply(): void
    {
        $shownIds = range(1, 99);

        $fiftyNamed = '{"duplicates": [' . implode(',', range(1, 50)) . ']}';
        $fortyNineNamed = '{"duplicates": [' . implode(',', range(1, 49)) . ']}';

        self::assertFalse($this->parser->parse($fiftyNamed, $shownIds)->usable);
        self::assertTrue($this->parser->parse($fortyNineNamed, $shownIds)->usable);
    }

    /** Ids the model invented are dropped before the share is judged, not counted into it. */
    public function testIdsNeverShownDoNotCountTowardsTheShare(): void
    {
        $result = $this->parser->parse('{"duplicates": [1, 2, 97, 98, 99]}', [1, 2, 3, 4]);

        self::assertTrue($result->usable);
        self::assertSame([1, 2], $result->duplicateIds);
    }
}
