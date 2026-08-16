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

        self::assertSame('plain answer', $this->decoder->envelope($body)['content']);
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
        self::assertNull($this->decoder->envelope('{"choices":[{"delta":{"content":"x"}}]}')['content']);
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
        self::assertNull($this->decoder->envelope('{"choices":[]}')['content']);
    }

    public function testMalformedJsonIsNullRatherThanFatal(): void
    {
        self::assertNull($this->decoder->envelope('not json')['content']);
        self::assertNull($this->decoder->deltaContent('not json at all'));
    }

    /**
     * The provider is untrusted, so every step of the walk can be the wrong
     * type. A non-string content must read as absent, not be coerced.
     */
    public function testANonStringContentIsNull(): void
    {
        self::assertNull($this->decoder->deltaContent('{"choices":[{"delta":{"content":42}}]}'));
        self::assertNull($this->decoder->envelope('{"choices":"nope"}')['content']);
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
            $this->decoder->envelope('{"choices":[{"message":{"reasoning_content":"{\"a\":1}"}}]}')['reasoning'],
        );
        self::assertSame(
            'thinking',
            $this->decoder->envelope('{"choices":[{"message":{"reasoning":"thinking"}}]}')['reasoning'],
        );
    }

    public function testAnEnvelopeWithoutReasoningHasNullReasoning(): void
    {
        self::assertNull($this->decoder->envelope('{"choices":[{"message":{"content":"x"}}]}')['reasoning']);
        self::assertNull($this->decoder->envelope('not json')['reasoning']);
    }

    public function testReadsTheUsageObjectOfTheFinalStreamMessage(): void
    {
        $usage = $this->decoder->envelope(
            '{"choices":[],"usage":{"prompt_tokens":118432,"completion_tokens":2216,'
            . '"cost":0.04123,"prompt_tokens_details":{"cached_tokens":117000},'
            . '"completion_tokens_details":{"reasoning_tokens":880}}}',
        )['usage'];

        self::assertNotNull($usage);
        self::assertSame(118432, $usage->promptTokens);
        self::assertSame(2216, $usage->completionTokens);
        self::assertSame(880, $usage->reasoningTokens);
        self::assertSame(117000, $usage->cachedTokens);
        self::assertSame(41_230_000, $usage->costNanoCredits);
    }

    public function testReadsUsageWithoutACostAsUnpriced(): void
    {
        $usage = $this->decoder->envelope('{"choices":[],"usage":{"prompt_tokens":40,"completion_tokens":9}}')['usage'];

        self::assertNotNull($usage);
        self::assertSame(40, $usage->promptTokens);
        self::assertSame(9, $usage->completionTokens);
        self::assertSame(0, $usage->reasoningTokens);
        self::assertSame(0, $usage->cachedTokens);
        self::assertNull($usage->costNanoCredits);
    }

    public function testReadsAnIntegerCostTheSameWayAsAFloatOne(): void
    {
        $usage = $this->decoder->envelope('{"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":2}}')['usage'];

        self::assertNotNull($usage);
        self::assertSame(2_000_000_000, $usage->costNanoCredits);
    }

    public function testHasNoUsageWhenTheProviderSentNone(): void
    {
        self::assertNull($this->decoder->envelope('{"choices":[{"delta":{"content":"hi"}}]}')['usage']);
    }

    public function testHasNoUsageWhenTheMemberIsNotAnObject(): void
    {
        self::assertNull($this->decoder->envelope('{"usage":"none"}')['usage']);
    }

    public function testHasNoUsageWhenThePayloadIsNotJson(): void
    {
        self::assertNull($this->decoder->envelope('not json')['usage']);
    }

    public function testIgnoresNonNumericProviderFields(): void
    {
        $usage = $this->decoder->envelope(
            '{"usage":{"prompt_tokens":"lots","completion_tokens":3,"cost":"free"}}',
        )['usage'];

        self::assertNotNull($usage);
        self::assertSame(0, $usage->promptTokens);
        self::assertSame(3, $usage->completionTokens);
        self::assertNull($usage->costNanoCredits);
    }

    /**
     * The counters are banked onto a running per-run total with SQL
     * arithmetic, so a negative one would subtract from calls that really
     * happened. It reads as the absent counter it may as well be.
     */
    public function testANegativeTokenCountReadsAsZero(): void
    {
        $usage = $this->decoder->envelope(
            '{"usage":{"prompt_tokens":-500,"completion_tokens":3,'
            . '"completion_tokens_details":{"reasoning_tokens":-1},'
            . '"prompt_tokens_details":{"cached_tokens":-1}}}',
        )['usage'];

        self::assertNotNull($usage);
        self::assertSame(0, $usage->promptTokens);
        self::assertSame(3, $usage->completionTokens);
        self::assertSame(0, $usage->reasoningTokens);
        self::assertSame(0, $usage->cachedTokens);
    }

    /**
     * A refund is not something a completion call reports, and the account's
     * all-time spend is a SUM with no floor under it.
     */
    public function testANegativeCostIsUnpricedRatherThanACredit(): void
    {
        $usage = $this->decoder->envelope('{"usage":{"prompt_tokens":1,"cost":-5}}')['usage'];

        self::assertNotNull($usage);
        self::assertNull($usage->costNanoCredits);
    }

    /**
     * JSON has no NAN literal, so INF — which `1e999` decodes to — is the only
     * non-finite value a provider can actually send; both leave through the
     * same guard.
     */
    public function testANonFiniteCostIsUnpriced(): void
    {
        self::assertNull($this->decoder->envelope('{"usage":{"cost":1e999}}')['usage']?->costNanoCredits);
        self::assertNull($this->decoder->envelope('{"usage":{"cost":-1e999}}')['usage']?->costNanoCredits);
    }

    /**
     * Casting an out-of-range float to int is undefined in PHP, so the cast
     * has to be unreachable rather than merely unlikely: one garbage value
     * corrupts the account's total, and the next one makes BIGINT reject the
     * write from inside the tick.
     */
    public function testACostTooLargeForTheNanoCreditIntegerIsUnpriced(): void
    {
        self::assertNull($this->decoder->envelope('{"usage":{"cost":1e30}}')['usage']?->costNanoCredits);
    }

    /**
     * The refusal above is a ceiling on the *nano* value, not a distrust of
     * large prices: a cost that still fits stays exact. The ceiling sits one
     * past the largest integer there is, so a cost landing exactly on it is
     * refused too — that is the value whose (int) cast wraps to the smallest.
     */
    public function testTheCeilingIsTheFirstNanoValueNoIntegerCanHold(): void
    {
        self::assertSame(
            9_000_000_000_000_000_000,
            $this->decoder->envelope('{"usage":{"cost":9000000000}}')['usage']?->costNanoCredits,
        );
        self::assertNull(
            $this->decoder->envelope('{"usage":{"cost":9223372036.854775808}}')['usage']?->costNanoCredits,
        );
    }

    /**
     * A price the provider states as free is priced at zero, which is a claim
     * — as against the null that means it stated nothing at all.
     */
    public function testACostOfZeroIsPricedAtZeroRatherThanUnpriced(): void
    {
        self::assertSame(0, $this->decoder->envelope('{"usage":{"cost":0}}')['usage']?->costNanoCredits);
    }

    /**
     * Nano-credits are the smallest unit the column holds, so the sub-nano
     * remainder of a float price is rounded to the nearest one rather than
     * always given to the provider or always to the account.
     */
    public function testASubNanoRemainderIsRoundedToTheNearestNanoCredit(): void
    {
        self::assertSame(
            123_456_790,
            $this->decoder->envelope('{"usage":{"cost":0.123456789987}}')['usage']?->costNanoCredits,
        );
        self::assertSame(
            123_456_789,
            $this->decoder->envelope('{"usage":{"cost":0.123456789123}}')['usage']?->costNanoCredits,
        );
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
