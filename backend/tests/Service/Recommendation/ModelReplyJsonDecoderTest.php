<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
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
     * A lone closing fence is not stripped as a fence, so the direct decode
     * fails — but the object before it is still recovered by the embedded
     * fallback (#323), the same path that lifts an answer out of reasoning text.
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

    /**
     * #323: LM Studio routes a reasoning model's answer through the reasoning
     * channel, where it can arrive wrapped in the model's thinking prose. The
     * decoder lifts the JSON object out of the surrounding text.
     */
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
}
