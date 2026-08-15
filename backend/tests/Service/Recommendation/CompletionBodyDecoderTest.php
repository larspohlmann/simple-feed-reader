<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CompletionBodyDecoder;
use PHPUnit\Framework\TestCase;

final class CompletionBodyDecoderTest extends TestCase
{
    private CompletionBodyDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new CompletionBodyDecoder();
    }

    public function testAnEnvelopeCarriesItsAnswerUnderMessage(): void
    {
        $body = '{"choices":[{"message":{"content":"plain answer"}}]}';

        self::assertSame('plain answer', $this->decoder->envelopeContent($body));
    }

    public function testAStreamEventCarriesItsAnswerUnderDelta(): void
    {
        $payload = '{"choices":[{"delta":{"content":"a fragment"}}]}';

        self::assertSame('a fragment', $this->decoder->deltaContent($payload));
    }

    /**
     * The two keys are the whole difference between the shapes, so each
     * method must refuse the other's. Without this the reader could join an
     * envelope's answer into a stream, or vice versa, and never notice.
     */
    public function testNeitherShapeAcceptsTheOthersKey(): void
    {
        self::assertNull($this->decoder->envelopeContent('{"choices":[{"delta":{"content":"x"}}]}'));
        self::assertNull($this->decoder->deltaContent('{"choices":[{"message":{"content":"x"}}]}'));
    }

    /**
     * A reasoning model's thinking phase arrives as deltas with no `content`
     * at all — the exact traffic that made #320's calls look empty while
     * megabytes flowed. Skipping them is the decoder's job, not the caller's.
     */
    public function testADeltaWithoutContentIsNull(): void
    {
        self::assertNull($this->decoder->deltaContent('{"choices":[{"delta":{"reasoning":"thinking…"}}]}'));
        self::assertNull($this->decoder->deltaContent('{"choices":[{"delta":{"role":"assistant"}}]}'));
    }

    public function testAUsageEventWithoutChoicesIsNull(): void
    {
        self::assertNull($this->decoder->deltaContent('{"choices":[],"usage":{"total_tokens":9}}'));
    }

    public function testAnEnvelopeWithoutContentIsNull(): void
    {
        self::assertNull($this->decoder->envelopeContent('{"choices":[]}'));
    }

    public function testMalformedJsonIsNullRatherThanFatal(): void
    {
        self::assertNull($this->decoder->envelopeContent('not json'));
        self::assertNull($this->decoder->deltaContent('not json at all'));
    }

    /**
     * The provider is untrusted, so every step of the walk can be the wrong
     * type. A non-string content must read as absent, not be coerced.
     */
    public function testANonStringContentIsNull(): void
    {
        self::assertNull($this->decoder->deltaContent('{"choices":[{"delta":{"content":42}}]}'));
        self::assertNull($this->decoder->envelopeContent('{"choices":"nope"}'));
    }

    /**
     * The provider stamps why generation stopped on the choice, beside the
     * delta rather than inside it. `length` is the signal that the answer was
     * truncated by `max_tokens` — the diagnosis the debug log could not make
     * before #327.
     */
    public function testTheFinishReasonRidesOnTheChoiceNotTheDelta(): void
    {
        self::assertSame(
            'length',
            $this->decoder->finishReason('{"choices":[{"delta":{"content":"x"},"finish_reason":"length"}]}'),
        );
        self::assertSame(
            'stop',
            $this->decoder->finishReason('{"choices":[{"delta":{},"finish_reason":"stop"}]}'),
        );
    }

    public function testAChoiceStillGeneratingHasNoFinishReason(): void
    {
        self::assertNull($this->decoder->finishReason('{"choices":[{"delta":{"content":"x"},"finish_reason":null}]}'));
        self::assertNull($this->decoder->finishReason('{"choices":[{"delta":{"content":"x"}}]}'));
    }

    public function testANonStringFinishReasonIsNullRatherThanCoerced(): void
    {
        self::assertNull($this->decoder->finishReason('{"choices":[{"finish_reason":7}]}'));
        self::assertNull($this->decoder->finishReason('not json'));
    }

    /**
     * The reader reads both fields of an event together, so the decoder yields
     * them from one decode. The answer's content and the finish reason travel
     * on the same event.
     */
    public function testAStreamEventYieldsBothContentAndFinishReasonFromOneDecode(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"content":"x"},"finish_reason":"length"}]}');

        self::assertSame('x', $event['content']);
        self::assertSame('length', $event['finishReason']);
    }

    public function testAStreamEventStillGeneratingCarriesContentButNoFinishReason(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"content":"x"}}]}');

        self::assertSame('x', $event['content']);
        self::assertNull($event['finishReason']);
    }

    public function testAStreamEventOfMalformedJsonIsAllNulls(): void
    {
        self::assertSame(
            ['content' => null, 'reasoning' => null, 'finishReason' => null, 'usage' => null],
            $this->decoder->streamEvent('not json'),
        );
    }

    /**
     * LM Studio delivers a reasoning model's whole answer under
     * `delta.reasoning_content`, leaving `content` empty (#323). The reader
     * needs that channel to recover an answer no other field carries.
     */
    public function testAStreamEventExposesTheReasoningContentChannel(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"reasoning_content":"{\"a\":1}"}}]}');

        self::assertSame('{"a":1}', $event['reasoning']);
        self::assertNull($event['content']);
    }

    /**
     * OpenRouter names the same channel `delta.reasoning`. One accessor covers
     * both spellings so the reader does not have to know which provider it is
     * talking to.
     */
    public function testAStreamEventExposesThePlainReasoningChannel(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"reasoning":"thinking"}}]}');

        self::assertSame('thinking', $event['reasoning']);
    }

    /**
     * When a model fills both spellings, `reasoning_content` (LM Studio's, the
     * one that carries the answer here) is the one read; `reasoning` is only the
     * fallback for providers that use it instead.
     */
    public function testTheReasoningContentSpellingIsPreferredOverReasoning(): void
    {
        $event = $this->decoder->streamEvent(
            '{"choices":[{"delta":{"reasoning_content":"primary","reasoning":"secondary"}}]}',
        );

        self::assertSame('primary', $event['reasoning']);
    }

    public function testAStreamEventWithoutAnyReasoningHasNullReasoning(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"content":"x"}}]}');

        self::assertNull($event['reasoning']);
    }

    public function testANonStringReasoningIsNullRatherThanCoerced(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"reasoning_content":42}}]}');

        self::assertNull($event['reasoning']);
    }

    /**
     * A provider that ignores `stream: true` answers with the blocking
     * envelope; a reasoning-only model puts the answer under
     * `message.reasoning_content` there, the same way it does per delta.
     */
    public function testAnEnvelopeExposesItsReasoningChannel(): void
    {
        self::assertSame(
            '{"a":1}',
            $this->decoder->envelopeReasoning('{"choices":[{"message":{"reasoning_content":"{\"a\":1}"}}]}'),
        );
        self::assertSame(
            'thinking',
            $this->decoder->envelopeReasoning('{"choices":[{"message":{"reasoning":"thinking"}}]}'),
        );
    }

    public function testAnEnvelopeWithoutReasoningHasNullReasoning(): void
    {
        self::assertNull($this->decoder->envelopeReasoning('{"choices":[{"message":{"content":"x"}}]}'));
        self::assertNull($this->decoder->envelopeReasoning('not json'));
    }

    public function testReadsTheUsageObjectOfTheFinalStreamMessage(): void
    {
        $usage = $this->decoder->usage(
            '{"choices":[],"usage":{"prompt_tokens":118432,"completion_tokens":2216,'
            . '"cost":0.04123,"prompt_tokens_details":{"cached_tokens":117000},'
            . '"completion_tokens_details":{"reasoning_tokens":880}}}',
        );

        self::assertNotNull($usage);
        self::assertSame(118432, $usage->promptTokens);
        self::assertSame(2216, $usage->completionTokens);
        self::assertSame(880, $usage->reasoningTokens);
        self::assertSame(117000, $usage->cachedTokens);
        self::assertSame(41_230_000, $usage->costNanoCredits);
    }

    public function testReadsUsageWithoutACostAsUnpriced(): void
    {
        $usage = $this->decoder->usage('{"choices":[],"usage":{"prompt_tokens":40,"completion_tokens":9}}');

        self::assertNotNull($usage);
        self::assertSame(40, $usage->promptTokens);
        self::assertSame(9, $usage->completionTokens);
        self::assertSame(0, $usage->reasoningTokens);
        self::assertSame(0, $usage->cachedTokens);
        self::assertNull($usage->costNanoCredits);
    }

    public function testReadsAnIntegerCostTheSameWayAsAFloatOne(): void
    {
        $usage = $this->decoder->usage('{"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":2}}');

        self::assertNotNull($usage);
        self::assertSame(2_000_000_000, $usage->costNanoCredits);
    }

    public function testHasNoUsageWhenTheProviderSentNone(): void
    {
        self::assertNull($this->decoder->usage('{"choices":[{"delta":{"content":"hi"}}]}'));
    }

    public function testHasNoUsageWhenTheMemberIsNotAnObject(): void
    {
        self::assertNull($this->decoder->usage('{"usage":"none"}'));
    }

    public function testHasNoUsageWhenThePayloadIsNotJson(): void
    {
        self::assertNull($this->decoder->usage('not json'));
    }

    public function testIgnoresNonNumericProviderFields(): void
    {
        $usage = $this->decoder->usage(
            '{"usage":{"prompt_tokens":"lots","completion_tokens":3,"cost":"free"}}',
        );

        self::assertNotNull($usage);
        self::assertSame(0, $usage->promptTokens);
        self::assertSame(3, $usage->completionTokens);
        self::assertNull($usage->costNanoCredits);
    }

    public function testStreamEventCarriesTheUsageAlongsideTheAnswerFields(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[],"usage":{"prompt_tokens":7,"completion_tokens":2}}');

        self::assertNull($event['content']);
        self::assertNull($event['finishReason']);
        self::assertNotNull($event['usage']);
        self::assertSame(7, $event['usage']->promptTokens);
    }

    public function testStreamEventHasNoUsageOnAnOrdinaryDelta(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"content":"hi"}}]}');

        self::assertSame('hi', $event['content']);
        self::assertNull($event['usage']);
    }
}
