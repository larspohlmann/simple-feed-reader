<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Completion\Pass;

use App\Service\Ai\Completion\CompletionBodyDecoder;
use App\Service\Ai\Completion\Pass\CompletionStreamReader;
use PHPUnit\Framework\TestCase;

final class CompletionStreamReaderTest extends TestCase
{
    private function reader(): CompletionStreamReader
    {
        return new CompletionStreamReader(new CompletionBodyDecoder());
    }

    /** One content-carrying SSE event; the payload is JSON-encoded, so quotes in $content cannot break the fixture. */
    private function contentEvent(string $content): string
    {
        $event = ['choices' => [['delta' => ['content' => $content]]]];

        return 'data: ' . json_encode($event, \JSON_THROW_ON_ERROR) . "\n\n";
    }

    private function reasoningEvent(string $reasoning): string
    {
        $event = ['choices' => [['delta' => ['reasoning' => $reasoning]]]];

        return 'data: ' . json_encode($event, \JSON_THROW_ON_ERROR) . "\n\n";
    }

    private function reasoningContentEvent(string $reasoning): string
    {
        $event = ['choices' => [['delta' => ['reasoning_content' => $reasoning]]]];

        return 'data: ' . json_encode($event, \JSON_THROW_ON_ERROR) . "\n\n";
    }

    private function finishEvent(string $reason): string
    {
        $event = ['choices' => [['delta' => [], 'finish_reason' => $reason]]];

        return 'data: ' . json_encode($event, \JSON_THROW_ON_ERROR) . "\n\n";
    }

    public function testJoinsTheContentDeltasOfAStreamedAnswer(): void
    {
        $reader = $this->reader();

        $reader->consume('data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n");
        $reader->consume($this->contentEvent('{"recommend'));
        $reader->consume($this->contentEvent('ations":[]}'));
        $reader->consume('data: [DONE]' . "\n\n");

        self::assertSame('{"recommendations":[]}', $reader->assistantContent());
    }

    public function testTheReaderRemembersWhyGenerationStopped(): void
    {
        $reader = $this->reader();

        $reader->consume($this->contentEvent('{}'));
        self::assertNull($reader->finishReason());

        $reader->consume($this->finishEvent('length'));
        $reader->consume('data: [DONE]' . "\n\n");

        self::assertSame('length', $reader->finishReason());
    }

    /**
     * A later event without a finish reason must not erase the one already
     * seen: providers stamp the reason then send a trailing usage-only event,
     * and the reason has to survive it.
     */
    public function testAKnownFinishReasonSurvivesALaterReasonlessEvent(): void
    {
        $reader = $this->reader();

        $reader->consume($this->finishEvent('stop'));
        $reader->consume('data: {"choices":[],"usage":{"total_tokens":9}}' . "\n\n");

        self::assertSame('stop', $reader->finishReason());
    }

    /** Reasoning deltas cost wire bytes but are not retained, so thinking never sits under the answer cap. */
    public function testReasoningCostsWireBytesButIsNotRetained(): void
    {
        $reader = $this->reader();
        $reasoning = $this->reasoningEvent(str_repeat('thinking. ', 5_000));

        $reader->consume($reasoning);

        self::assertNull($reader->assistantContent());
        self::assertSame(\strlen($reasoning), $reader->wireBytes());
        self::assertSame(0, $reader->retainedBytes());
    }

    /**
     * Retained bytes must track the answer, not the traffic: it is the number
     * the client caps, so if reasoning leaked into it the cap would fire on
     * a healthy call again.
     */
    public function testRetainedBytesTrackTheAnswerNotTheWire(): void
    {
        $reader = $this->reader();

        $reader->consume($this->reasoningEvent(str_repeat('x', 10_000)));
        $reader->consume($this->contentEvent('four'));

        self::assertSame(4, $reader->retainedBytes());
        self::assertGreaterThan(10_000, $reader->wireBytes());
    }

    /**
     * Chunk boundaries are the transport's business and fall anywhere — mid
     * event, mid JSON, between the two newlines. The reader must join across
     * them rather than parse each chunk on its own.
     */
    public function testAnEventSplitAcrossChunksIsStillDecoded(): void
    {
        $reader = $this->reader();
        $event = $this->contentEvent('whole');

        foreach (str_split($event, 7) as $piece) {
            $reader->consume($piece);
        }

        self::assertSame('whole', $reader->assistantContent());
        self::assertSame(\strlen($event), $reader->wireBytes());
    }

    /** Providers routed through some proxies deliver CRLF line endings. */
    public function testACrlfSeparatedStreamDecodesToo(): void
    {
        $reader = $this->reader();

        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\"one\"}}]}\r\n\r\n");
        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\" two\"}}]}\r\n\r\n");
        $reader->consume("data: [DONE]\r\n\r\n");

        self::assertSame('one two', $reader->assistantContent());
    }

    public function testAMalformedEventIsSkippedNotFatal(): void
    {
        $reader = $this->reader();

        $reader->consume('data: not json at all' . "\n\n");
        $reader->consume($this->contentEvent('still here'));

        self::assertSame('still here', $reader->assistantContent());
    }

    /**
     * OpenRouter sends `: PROCESSING` keep-alive comments during a long
     * thinking phase. They must neither break the shape detection nor be
     * retained as if they were an envelope.
     */
    public function testKeepAliveCommentsBeforeTheFirstEventAreDropped(): void
    {
        $reader = $this->reader();

        $reader->consume(": OPENROUTER PROCESSING\n\n");
        $reader->consume(": OPENROUTER PROCESSING\n\n");
        $reader->consume($this->contentEvent('answer'));

        self::assertSame('answer', $reader->assistantContent());
        self::assertSame(6, $reader->retainedBytes());
    }

    public function testAStreamWithNoContentAtAllIsNull(): void
    {
        $reader = $this->reader();

        $reader->consume('data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n");
        $reader->consume('data: [DONE]' . "\n\n");

        self::assertNull($reader->assistantContent());
    }

    /**
     * A provider that ignores `stream: true` answers with the blocking
     * envelope; that path must keep working, including when the whole body
     * arrives as one line with no trailing newline at all.
     */
    public function testABlockingEnvelopeStillDecodes(): void
    {
        $reader = $this->reader();

        $reader->consume('{"choices":[{"message":{"content":"plain answer"}}]}');

        self::assertSame('plain answer', $reader->assistantContent());
    }

    public function testAPrettyPrintedEnvelopeSpanningLinesStillDecodes(): void
    {
        $reader = $this->reader();

        $reader->consume("{\n  \"choices\": [\n");
        $reader->consume("    {\"message\": {\"content\": \"multi line\"}}\n  ]\n}");

        self::assertSame('multi line', $reader->assistantContent());
    }

    /**
     * An envelope whose content contains the substring "data:" mid-line must
     * still read as an envelope: only a line-initial "data:" starts a stream.
     */
    public function testAnEnvelopeContainingDataMidLineIsNotMisreadAsAStream(): void
    {
        $reader = $this->reader();

        $reader->consume('{"choices":[{"message":{"content":"see data: below"}}]}');

        self::assertSame('see data: below', $reader->assistantContent());
    }

    public function testANonJsonBodyIsNull(): void
    {
        $reader = $this->reader();

        $reader->consume('not json');

        self::assertNull($reader->assistantContent());
    }

    /**
     * A stream cut mid-flight, or one whose last event lacks its closing newline, still yields the deltas that did
     * arrive, including that final unterminated one.
     */
    public function testAFinalEventWithoutItsClosingNewlineIsStillRead(): void
    {
        $reader = $this->reader();

        $reader->consume($this->contentEvent('first '));
        $reader->consume(rtrim($this->contentEvent('last'), "\n"));

        self::assertSame('first last', $reader->assistantContent());
    }

    public function testATruncatedFinalEventContributesNothing(): void
    {
        $reader = $this->reader();

        $reader->consume($this->contentEvent('kept'));
        $reader->consume('data: {"choices":[{"delta":{"cont');

        self::assertSame('kept', $reader->assistantContent());
    }

    /**
     * Reading the answer must not consume it: the client asks after every
     * chunk to report progress, and again at the end for the result.
     */
    public function testReadingTheAnswerRepeatedlyIsStable(): void
    {
        $reader = $this->reader();

        $reader->consume($this->contentEvent('once'));

        self::assertSame('once', $reader->assistantContent());
        self::assertSame('once', $reader->assistantContent());
    }

    /**
     * LM Studio can put a reasoning model's whole answer under `reasoning_content`. The reader exposes it for recovery
     * while assistantContent() stays empty and retainedBytes() stays zero.
     */
    public function testTheReasoningChannelIsExposedForRecovery(): void
    {
        $reader = $this->reader();

        $reader->consume($this->reasoningContentEvent('{"recommendations":[]}'));
        $reader->consume('data: [DONE]' . "\n\n");

        self::assertNull($reader->assistantContent());
        self::assertSame('{"recommendations":[]}', $reader->reasoningContent());
        self::assertSame(0, $reader->retainedBytes());
    }

    public function testTheReasoningChannelJoinsAcrossEvents(): void
    {
        $reader = $this->reader();

        $reader->consume($this->reasoningContentEvent('{"recomm'));
        $reader->consume($this->reasoningContentEvent('endations":[]}'));

        self::assertSame('{"recommendations":[]}', $reader->reasoningContent());
    }

    /**
     * Both channels can arrive on one call. They stay separate: the content is
     * the answer, the reasoning is only the fallback the client reaches for
     * when the content channel is empty.
     */
    public function testContentAndReasoningAreKeptApart(): void
    {
        $reader = $this->reader();

        $reader->consume($this->reasoningContentEvent('thinking'));
        $reader->consume($this->contentEvent('{"recommendations":[]}'));

        self::assertSame('{"recommendations":[]}', $reader->assistantContent());
        self::assertSame('thinking', $reader->reasoningContent());
    }

    public function testAStreamWithNeitherContentNorReasoningHasNullReasoning(): void
    {
        $reader = $this->reader();

        $reader->consume('data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n");
        $reader->consume('data: [DONE]' . "\n\n");

        self::assertNull($reader->reasoningContent());
    }

    /** The reasoning tail is bounded, keeps its END (where the answer sits) and is never charged to the answer cap. */
    public function testTheReasoningTailIsBoundedButKeepsTheTrailingAnswer(): void
    {
        $reader = $this->reader();
        $answer = '{"recommendations":[]}';
        $filler = str_repeat('x', CompletionStreamReader::REASONING_TAIL_LIMIT);

        $reader->consume($this->reasoningContentEvent($filler . $answer));

        $tail = $reader->reasoningContent();
        self::assertNotNull($tail);
        // Exactly the bound, not merely under it: the buffer held limit+answer
        // and was trimmed from the front, so the trailing answer survives while
        // the length lands on the cap.
        self::assertSame(CompletionStreamReader::REASONING_TAIL_LIMIT, \strlen($tail));
        self::assertStringEndsWith($answer, $tail);
        self::assertSame(0, $reader->retainedBytes());
    }

    /**
     * The blocking-envelope reasoning path reconstructs the body from the
     * accumulated lines plus the trailing partial line, so both parts, in order,
     * have to reach the decoder.
     */
    public function testAPrettyPrintedEnvelopeReasoningSpanningLinesStillDecodes(): void
    {
        $reader = $this->reader();

        $reader->consume("{\n  \"choices\": [\n");
        $reader->consume("    {\"message\": {\"reasoning_content\": \"{}\"}}\n  ]\n}");

        self::assertSame('{}', $reader->reasoningContent());
    }

    /**
     * A provider that ignores `stream: true` and answers with the blocking
     * envelope can still route the answer through the reasoning channel.
     */
    public function testABlockingEnvelopeExposesItsReasoningForRecovery(): void
    {
        $reader = $this->reader();

        $reader->consume('{"choices":[{"message":{"reasoning_content":"{\"recommendations\":[]}"}}]}');

        self::assertNull($reader->assistantContent());
        self::assertSame('{"recommendations":[]}', $reader->reasoningContent());
    }

    public function testKeepsTheUsageOfTheFinalStreamMessage(): void
    {
        $reader = $this->reader();

        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n");
        $reader->consume(
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":12,\"completion_tokens\":3,\"cost\":0.000001}}\n\n",
        );
        $reader->consume("data: [DONE]\n\n");

        $usage = $reader->usage();
        self::assertNotNull($usage);
        self::assertSame(12, $usage->promptTokens);
        self::assertSame(1000, $usage->costNanoCredits);
    }

    public function testAnEventWithoutUsageNeverErasesTheUsageAlreadySeen(): void
    {
        $reader = $this->reader();

        $reader->consume("data: {\"choices\":[],\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":1}}\n\n");
        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\"tail\"}}]}\n\n");

        self::assertSame(5, $reader->usage()?->promptTokens);
    }

    public function testReadsTheUsageOfABlockingEnvelope(): void
    {
        $reader = $this->reader();

        $reader->consume(
            '{"choices":[{"message":{"content":"hi"}}],"usage":{"prompt_tokens":8,"completion_tokens":2}}',
        );

        self::assertSame(8, $reader->usage()?->promptTokens);
    }

    /**
     * The blocking shape's answer and usage are read on every chunk, first of a half-arrived body; their shared decode
     * must follow the buffer, not keep its first answer.
     */
    public function testABlockingEnvelopeReadWhileStillArrivingAnswersWithTheFinishedBody(): void
    {
        $reader = $this->reader();

        $reader->consume('{"choices":[{"message":{"content":"half');
        self::assertNull($reader->assistantContent());
        self::assertNull($reader->usage());

        $reader->consume(' and half"}}],"usage":{"prompt_tokens":8,"completion_tokens":2}}');

        self::assertSame('half and half', $reader->assistantContent());
        self::assertSame(8, $reader->usage()?->promptTokens);
    }

    public function testHasNoUsageWhenTheProviderNeverSentOne(): void
    {
        $reader = $this->reader();

        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n");

        self::assertNull($reader->usage());
    }

    /** A blocking envelope's `finish_reason` must reach hitTokenCeiling(), or runaways go unseen on that shape. */
    public function testABlockingEnvelopeReportsItsTokenCeiling(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());
        $reader->consume(json_encode([
            'choices' => [['message' => ['content' => '{"recommendations":[]}'], 'finish_reason' => 'length']],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame('length', $reader->finishReason());
        self::assertTrue($reader->hitTokenCeiling());
    }

    /**
     * A blocking envelope that ended naturally is not a runaway.
     */
    public function testABlockingEnvelopeThatStoppedNaturallyIsNoCeilingHit(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());
        $reader->consume(json_encode([
            'choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']],
        ], \JSON_THROW_ON_ERROR));

        self::assertFalse($reader->hitTokenCeiling());
    }

    /**
     * The buffered blocking body is not charged to the answer bound: nothing is an answer until it parses, and a
     * reasoning model's 540 KB of `reasoning_content` there once tripped the bound.
     */
    public function testABufferedBlockingBodyIsNotChargedToTheAnswerBound(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());
        $reader->consume(json_encode([
            'choices' => [['message' => [
                'reasoning_content' => str_repeat('thinking ', 60000),
                'content' => '{"recommendations":[]}',
            ]]],
        ], \JSON_THROW_ON_ERROR));

        self::assertGreaterThan(500_000, $reader->retainedBytes());
        self::assertSame(0, $reader->answerBytes());
    }

    /**
     * The streaming shape is where the answer is known incrementally, and
     * there the bound still measures it.
     */
    public function testAStreamedAnswerIsChargedToTheAnswerBound(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());
        $reader->consume('data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 500)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n");

        self::assertSame(500, $reader->answerBytes());
    }
}
