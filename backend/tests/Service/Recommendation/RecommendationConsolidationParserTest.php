<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use App\Service\Recommendation\RecommendationConsolidationParser;
use PHPUnit\Framework\TestCase;

final class RecommendationConsolidationParserTest extends TestCase
{
    private RecommendationConsolidationParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RecommendationConsolidationParser(new ModelReplyJsonDecoder());
    }

    public function testParsesPicksAndDuplicates(): void
    {
        $json = '{"recommendations":[{"id":5,"score":900,"reason":"On Rust."},{"id":6,"score":300,"reason":"Weak."}],'
            . '"duplicates":[6]}';

        $result = $this->parser->parse($json, [5, 6]);

        self::assertTrue($result->usable);
        self::assertSame([5, 6], array_map(static fn ($p) => $p->entryId, $result->picks));
        self::assertSame([6], $result->duplicateIds);
    }

    public function testRejectsWhenNoPicksSurvive(): void
    {
        self::assertFalse($this->parser->parse('{"recommendations":[],"duplicates":[]}', [5])->usable);
    }

    public function testDropsDuplicateIdsNotShown(): void
    {
        $json = '{"recommendations":[{"id":5,"score":900,"reason":"x"}],"duplicates":[999]}';
        $result = $this->parser->parse($json, [5]);
        self::assertSame([], $result->duplicateIds); // 999 was never shown
    }

    /** "No duplicates" is a legitimate answer, the same as RecommendationDuplicateParser. */
    public function testAnEmptyDuplicatesArrayIsUsable(): void
    {
        $json = '{"recommendations":[{"id":5,"score":900,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertTrue($result->usable);
        self::assertSame([], $result->duplicateIds);
    }

    /** A reply that omits "duplicates" entirely is not malformed — it answered nothing on that question. */
    public function testAMissingDuplicatesKeyDefaultsToAnEmptyList(): void
    {
        $result = $this->parser->parse('{"recommendations":[{"id":5,"score":900,"reason":"x"}]}', [5]);

        self::assertTrue($result->usable);
        self::assertSame([], $result->duplicateIds);
    }

    /** Ids the model invented are dropped; a valid remainder still counts as usable. */
    public function testUnknownIdsAreDroppedButValidPicksRemain(): void
    {
        $json = '{"recommendations":[{"id":5,"score":900,"reason":"x"},{"id":999,"score":100,"reason":"y"}],'
            . '"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertTrue($result->usable);
        self::assertSame([5], array_map(static fn ($p) => $p->entryId, $result->picks));
    }

    /** A score above the scale is clamped, mirroring RecommendationPickParser. */
    public function testScoresAreClampedToTheMaximum(): void
    {
        $json = '{"recommendations":[{"id":5,"score":5000,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertSame(1000, $result->picks[0]->score);
    }

    /** A missing reason defaults to '' rather than discarding an otherwise-valid pick. */
    public function testAMissingReasonDefaultsToAnEmptyString(): void
    {
        $json = '{"recommendations":[{"id":5,"score":900}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertSame('', $result->picks[0]->reason);
    }

    /**
     * A reply naming most of the shortlist as duplicates is rejected whole —
     * including its otherwise-valid picks — mirroring
     * RecommendationDuplicateParser's PlausibleDuplicateShare guard (#396).
     */
    public function testRejectsTheWholeReplyWhenTheDuplicateShareIsImplausible(): void
    {
        $shownIds = range(1, 4);
        $recommendations = array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 500, 'reason' => 'x'],
            $shownIds,
        );
        $json = json_encode(
            ['recommendations' => $recommendations, 'duplicates' => [1, 2, 3]],
            \JSON_THROW_ON_ERROR,
        );

        $result = $this->parser->parse($json, $shownIds);

        self::assertFalse($result->usable);
    }

    public function testUnparsableJsonIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('not json', [5])->usable);
    }

    public function testMissingRecommendationsKeyIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('{"duplicates":[]}', [5])->usable);
    }
}
