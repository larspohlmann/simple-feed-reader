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
        $result = $this->parser->parse('{"duplicates": [2, 3]}', [1, 2, 3]);

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

    public function testNumericStringIdsAreAcceptedAndRepeatsCollapsed(): void
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
        $result = $this->parser->parse("```json\n{\"duplicates\": [1]}\n```", [1]);

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
}
