<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CompletionBodyDecoder;
use App\Service\Recommendation\CompletionStreamReader;
use PHPUnit\Framework\TestCase;

final class CompletionStreamReaderTest extends TestCase
{
    private function reader(): CompletionStreamReader
    {
        return new CompletionStreamReader(new CompletionBodyDecoder());
    }

    /**
     * One content-carrying SSE event. The framing is spelled out here because
     * it is what the reader parses; the payload is encoded rather than typed
     * by hand, so a test whose content contains quotes cannot be defeated by
     * an escaping slip in the fixture.
     */
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

    /**
     * The point of #320. Reasoning deltas carry no content, so the answer
     * stays empty while the wire count climbs — and, decisively, the reader
     * retains none of them. Without this, a thinking model's transcript sat
     * in memory under the answer's cap and killed the call.
     */
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
     * #308's salvage depends on this: a stream cut mid-flight, or one whose
     * last event simply lacks its closing newline, must still yield the
     * deltas that did arrive — including that final unterminated one.
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
     * #323: LM Studio delivers a reasoning model's whole answer under
     * `reasoning_content` and leaves `content` empty. The reader exposes that
     * channel so the client can recover an answer the content channel never
     * carried — while `assistantContent()` stays empty, keeping the observer
     * and the debug log unchanged, and `retainedBytes()` stays zero, keeping
     * #320's answer cap unmoved.
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

    /**
     * A rambling reasoning phase can run to megabytes, so the retained tail is
     * bounded. It keeps the END, where a reasoning model puts the JSON answer
     * right before it stops — and it is never charged to the answer cap, so a
     * runaway thinking phase cannot fail a healthy call the way #320 did.
     */
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
}
