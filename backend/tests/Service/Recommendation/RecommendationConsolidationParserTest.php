<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use App\Service\Recommendation\RecommendationConsolidationParser;
use App\Service\Recommendation\RecommendationPickSalvager;
use PHPUnit\Framework\TestCase;

final class RecommendationConsolidationParserTest extends TestCase
{
    private RecommendationConsolidationParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RecommendationConsolidationParser(
            new ModelReplyJsonDecoder(),
            new RecommendationPickSalvager(),
        );
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

    /** "No duplicates" is a legitimate answer, not a malformed one. */
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
     * including its otherwise-valid picks — mirroring the PlausibleDuplicateShare
     * guard (#396).
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

    /** A malformed entry is skipped, not treated as a reason to stop reading the rest of the reply. */
    public function testAnInvalidEntryIsSkippedAndLaterValidEntriesStillSurvive(): void
    {
        $json = '{"recommendations":[{"id":"not-a-number"},{"id":5,"score":900,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertTrue($result->usable);
        self::assertSame([5], array_map(static fn ($p) => $p->entryId, $result->picks));
    }

    /** A fractional score rounds to the nearest whole number rather than always down. */
    public function testAFractionalScoreRoundsToTheNearestWholeNumber(): void
    {
        $json = '{"recommendations":[{"id":5,"score":500.3,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertSame(500, $result->picks[0]->score);
    }

    /** The other half of the previous case: rounding must also go up, not always down to the floor. */
    public function testAFractionalScoreAboveTheHalfwayPointRoundsUp(): void
    {
        $json = '{"recommendations":[{"id":5,"score":500.7,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertSame(501, $result->picks[0]->score);
    }

    /** The floor is 0, not 1 — a score that rounds to zero must stay zero. */
    public function testAZeroScoreStaysZeroRatherThanBeingLiftedToOne(): void
    {
        $json = '{"recommendations":[{"id":5,"score":0,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertSame(0, $result->picks[0]->score);
    }

    /**
     * is_int() and is_float() are mutually exclusive for any one PHP value,
     * so a score has to genuinely be one or the other to reach the numeric
     * branch — a non-numeric string must still be rejected outright rather
     * than being silently cast to 0.
     */
    public function testANonNumericScoreIsRejectedRatherThanCastToZero(): void
    {
        $json = '{"recommendations":[{"id":5,"score":"garbage","reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertFalse($result->usable);
    }

    /** A pick id given as a numeric string is coerced to int, same as a duplicate id. */
    public function testAPickEntryIdGivenAsANumericStringIsCoerced(): void
    {
        $json = '{"recommendations":[{"id":"6","score":900,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [6]);

        self::assertTrue($result->usable);
        self::assertSame(6, $result->picks[0]->entryId);
    }

    /**
     * A string that merely starts with digits is not itself all digits, and
     * must not be salvaged by truncating it to its leading numeric prefix.
     */
    public function testAPickEntryIdGivenAsANonDigitStringIsRejected(): void
    {
        $json = '{"recommendations":[{"id":"5x","score":900,"reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertFalse($result->usable);
    }

    /**
     * A numeric string is a legitimate score, not a reason to reject the
     * pick — the elseif branch exists specifically for this case, as
     * distinct from the int/float branch above it.
     */
    public function testANumericStringScoreIsAccepted(): void
    {
        $json = '{"recommendations":[{"id":5,"score":"900","reason":"x"}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertTrue($result->usable);
        self::assertSame(900, $result->picks[0]->score);
    }

    /** A reason of only whitespace is normalized to '', same as an empty one. */
    public function testAWhitespaceOnlyReasonIsNormalizedToEmpty(): void
    {
        $json = '{"recommendations":[{"id":5,"score":900,"reason":"   "}],"duplicates":[]}';

        $result = $this->parser->parse($json, [5]);

        self::assertSame('', $result->picks[0]->reason);
    }

    /**
     * A duplicate id given as a numeric string is coerced to int before the
     * shown-pool check. shownIds is sized to three so the single named
     * duplicate stays within PlausibleDuplicateShare's bound (max 1 of 3) --
     * a one-shown pool would trip that guard first and mask the coercion
     * this test targets behind ConsolidationParseResult::unusable()'s own
     * empty duplicateIds.
     */
    public function testDuplicateIdsGivenAsNumericStringsAreCoerced(): void
    {
        $json = '{"recommendations":[{"id":6,"score":900,"reason":"x"}],"duplicates":["6"]}';

        $result = $this->parser->parse($json, [6, 7, 8]);

        self::assertSame([6], $result->duplicateIds);
    }

    /**
     * An id in a plausible-sized pool that was never shown is dropped even
     * though it parsed as an int — the shown-pool membership check still has
     * to run, not merely the type check.
     */
    public function testAnIntegerDuplicateIdNotInTheShownPoolIsDropped(): void
    {
        $recommendations = array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 500, 'reason' => 'x'],
            [1, 2, 3],
        );
        $json = json_encode(
            ['recommendations' => $recommendations, 'duplicates' => [999]],
            \JSON_THROW_ON_ERROR,
        );

        $result = $this->parser->parse($json, [1, 2, 3, 4, 5, 6]);

        self::assertTrue($result->usable);
        self::assertSame([], $result->duplicateIds);
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
