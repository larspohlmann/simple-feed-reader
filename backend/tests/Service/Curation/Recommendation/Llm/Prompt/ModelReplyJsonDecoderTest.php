<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Prompt;

use App\Service\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;
use PHPUnit\Framework\TestCase;

final class ModelReplyJsonDecoderTest extends TestCase
{
    private ModelReplyJsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new ModelReplyJsonDecoder();
    }

    public function testPlainJsonObjectDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode('{"a": 1}'));
    }

    public function testFencedJsonWithLanguageTagDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```json\n{\"a\": 1}\n```"));
    }

    public function testFencedJsonWithoutLanguageTagDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```\n{\"a\": 1}\n```"));
    }

    public function testSurroundingWhitespaceIsTrimmedBeforeFenceDetection(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("  \n```json\n{\"a\": 1}\n```\n  "));
    }

    public function testFenceClosingImmediatelyAfterTheJsonIsStripped(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```json\n{\"a\": 1}```"));
    }

    /**
     * A lone closing fence is not stripped as a fence, so the direct decode fails, but the embedded-object fallback
     * still recovers the object before it.
     */
    public function testAnObjectFollowedByStrayCharactersIsStillRecovered(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode('{"a": 1}```'));
    }

    public function testNonJsonReturnsNull(): void
    {
        self::assertNull($this->decoder->decode('not json'));
    }

    public function testScalarJsonReturnsNull(): void
    {
        self::assertNull($this->decoder->decode('42'));
    }

    public function testAJsonObjectEmbeddedInSurroundingTextIsExtracted(): void
    {
        self::assertSame(
            ['recommendations' => []],
            $this->decoder->decode('Let me think. The ranking is {"recommendations": []} — done.'),
        );
    }

    /**
     * A thinking phase may draft one shape before committing to another; the
     * committed answer is the last complete object, so that is the one kept.
     */
    public function testTheLastCompleteObjectWinsWhenSeveralArePresent(): void
    {
        self::assertSame(
            ['a' => 2],
            $this->decoder->decode('first {"a": 1} on reflection {"a": 2}'),
        );
    }

    /**
     * A brace inside a string value must not end the object early: the scanner
     * has to respect JSON string literals, or a reason like "10} points" would
     * truncate the answer.
     */
    public function testBracesInsideStringValuesDoNotEndTheObjectEarly(): void
    {
        self::assertSame(
            ['recommendations' => [['id' => 1, 'reason' => 'scored 10} points']]],
            $this->decoder->decode('answer: {"recommendations": [{"id": 1, "reason": "scored 10} points"}]}'),
        );
    }

    public function testTextWithNoJsonObjectIsNull(): void
    {
        self::assertNull($this->decoder->decode('just thinking out loud, no answer yet'));
    }

    /**
     * A whole-reply array decodes directly and must not be dropped in favour of
     * the embedded-object scan, which only looks for `{...}`.
     */
    public function testATopLevelJsonArrayDecodesDirectly(): void
    {
        self::assertSame([1, 2, 3], $this->decoder->decode('[1, 2, 3]'));
    }

    /**
     * A stray closing brace before the real object must not corrupt the depth
     * counter: the scanner only decrements on a brace it actually opened.
     */
    public function testALeadingStrayBraceDoesNotCorruptTheScan(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode('} {"a": 1}'));
    }

    /**
     * An empty string value immediately before a brace-bearing string value:
     * the scanner must land on each string's real closing quote, or the brace
     * inside the later value ends the object early.
     */
    public function testAnEmptyStringValueBeforeABraceInAStringIsHandled(): void
    {
        self::assertSame(
            ['a' => '', 'b' => '}'],
            $this->decoder->decode('answer: {"a": "", "b": "}"}'),
        );
    }

    public function testAnObjectCutRightAfterItsOpeningBraceLeavesTheCompleteOneBeforeIt(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode('{"a": 1} {'));
    }

    public function testTheCompleteItemsOfAnArrayCutInsideAStringAreRecoveredInOrder(): void
    {
        self::assertSame(
            [['id' => 1, 'reason' => 'scored 10} points'], ['id' => 2, 'reason' => 'b']],
            $this->decoder->completeItemsOf(
                '{"recommendations": [{"id": 1, "reason": "scored 10} points"}, {"id": 2, "reason": "b"}, '
                . '{"id": 3, "reason": "cut o',
                'recommendations',
            ),
        );
    }

    public function testAnItemCutBetweenItsFieldsIsLeftOutOfACompactArray(): void
    {
        self::assertSame(
            [['id' => 1]],
            $this->decoder->completeItemsOf('{"recommendations":[{"id":1},{"id":2,"sc', 'recommendations'),
        );
    }

    public function testAnUndecodableObjectBetweenItemsIsSkipped(): void
    {
        self::assertSame(
            [['id' => 1], ['id' => 2]],
            $this->decoder->completeItemsOf(
                '{"recommendations": [{"id": 1}, {id: 7}, {"id": 2}, {"id"',
                'recommendations',
            ),
        );
    }

    /** Prose around the reply may name the key half-quoted; only the exact key opens the array. */
    public function testOnlyTheQuotedKeyOpensTheArray(): void
    {
        self::assertSame(
            [['id' => 1]],
            $this->decoder->completeItemsOf(
                'Plan: "recommendations first [a], then recommendations" [b].' . "\n"
                . '{"recommendations": [{"id": 1}, {"id": 2',
                'recommendations',
            ),
        );
    }

    public function testAReplyThatNeverOpenedTheArrayHasNoItems(): void
    {
        self::assertSame([], $this->decoder->completeItemsOf('{"recommendations": ', 'recommendations'));
        self::assertSame([], $this->decoder->completeItemsOf('{"profile": "x"}', 'recommendations'));
    }
}
