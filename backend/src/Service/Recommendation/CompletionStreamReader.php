<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Reads one /chat/completions response as it arrives and keeps only the
 * answer.
 *
 * Incremental rather than "accumulate then decode" (#320): a reasoning model
 * streams its whole thinking phase as SSE events with no `content`, so the
 * transcript runs to megabytes while the answer stays empty. Retaining it put
 * reasoning and framing under the answer's cap and killed working calls;
 * dropping each event once decoded bounds memory by the answer, as intended.
 *
 * Deliberately not readonly: one reader is one call's worth of state.
 */
final class CompletionStreamReader
{
    /**
     * A reasoning phase can run to megabytes (#320), so only the tail of the
     * reasoning channel is kept — where LM Studio's models put the JSON answer
     * before they stop (#323). Self-bounding, not charged to retainedBytes():
     * reasoning must never count against the answer's cap (#320).
     */
    public const int REASONING_TAIL_LIMIT = 2_097_152;

    private string $pendingLine = '';
    private string $answer = '';
    private string $reasoning = '';
    private string $envelope = '';
    private bool $sawStreamEvent = false;
    private int $wireBytes = 0;
    private ?string $finishReason = null;

    /**
     * The provider's own accounting for this call, sticky exactly as
     * $finishReason is: it arrives in one late message and every event after
     * it carries none, so a later null must never erase it (#409).
     */
    private ?CompletionUsage $usage = null;

    /**
     * How many times the buffers have changed — the key the blocking-envelope
     * readers share one decode per generation on. Buffers change only inside
     * consume(); their combined length is no stand-in, since a CRLF break
     * leaves exactly as many bytes as it removes.
     */
    private int $bufferGeneration = 0;

    /**
     * The last blocking-envelope decode and the generation it was taken at.
     *
     * @var array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsage}|null
     */
    private ?array $envelopeFields = null;
    private int $envelopeFieldsGeneration = -1;

    public function __construct(private readonly CompletionBodyDecoder $decoder)
    {
    }

    public function consume(string $chunk): void
    {
        $this->wireBytes += \strlen($chunk);
        $this->pendingLine .= $chunk;
        $this->bufferGeneration++;

        while (false !== ($lineBreak = strpos($this->pendingLine, "\n"))) {
            $line = substr($this->pendingLine, 0, $lineBreak);
            $this->pendingLine = substr($this->pendingLine, $lineBreak + 1);
            $this->readLine(rtrim($line, "\r"));
        }
    }

    /** Every byte the provider sent, answer and reasoning and framing alike. */
    public function wireBytes(): int
    {
        return $this->wireBytes;
    }

    /**
     * Why the provider stopped generating, once it says so — `length` when
     * `max_tokens` truncated the answer, `stop` on a natural end. Null until an
     * event carries it; once carried it stays, so a trailing usage-only event
     * cannot erase it.
     */
    public function finishReason(): ?string
    {
        if (!$this->sawStreamEvent) {
            return $this->envelopeFields()['finishReason'];
        }

        return $this->finishReason;
    }

    /**
     * Whether the provider stopped because `max_tokens` stopped it.
     *
     * The judgement lives here, not in the client, because this class and
     * CompletionBodyDecoder hold every other provider dialect — #323's
     * `reasoning_content` recovery, #409's sticky usage, the `$finishReason`
     * stickiness this depends on. #437 compared the raw `'length'` in the HTTP
     * client, the layer furthest from the wire; an endpoint that spells the
     * ceiling differently is then a one-line change here beside the dialects.
     */
    public function hitTokenCeiling(): bool
    {
        return 'length' === $this->finishReason();
    }

    /**
     * What the provider says this call consumed, once it says so. Null until a
     * message carries it, and for a provider that reports none.
     *
     * No salvage of an unterminated event in $pendingLine the way
     * trailingEventContent() salvages a last delta: that is a JSON decode per
     * chunk, the parse cost #327 removed, and the usage message is followed by
     * `data: [DONE]`, so it is never the unterminated one. The blocking shape
     * re-reads its whole buffer but shares that decode with assistantContent(),
     * which the client asks for on the same chunk.
     */
    public function usage(): ?CompletionUsage
    {
        if (!$this->sawStreamEvent) {
            return $this->envelopeFields()['usage'];
        }

        return $this->usage;
    }

    /**
     * What this reader is actually holding on to — the flat memory bound. On
     * the blocking shape it counts the buffered body, reasoning and framing
     * included, because that is what is really in memory.
     */
    public function retainedBytes(): int
    {
        return \strlen($this->answer) + \strlen($this->envelope) + \strlen($this->pendingLine);
    }

    /**
     * How much *answer* is held — what a `max_tokens`-derived bound may measure.
     *
     * Zero on the blocking shape, deliberately: nothing is an answer until the
     * whole body parses, and the buffer holds framing and a reasoning model's
     * whole thinking phase. Charging that to the answer bound flagged a legit
     * 540 KB reasoning reply as a runaway, under the 1.9 MB #320 calls normal
     * (#437 review); the blocking shape is bounded by retainedBytes() instead.
     */
    public function answerBytes(): int
    {
        if (!$this->sawStreamEvent) {
            return 0;
        }

        return \strlen($this->answer) + \strlen($this->pendingLine);
    }

    public function assistantContent(): ?string
    {
        if (!$this->sawStreamEvent) {
            return $this->envelopeFields()['content'];
        }

        $answer = $this->answer . $this->trailingEventContent();

        return '' === $answer ? null : $answer;
    }

    /**
     * The reasoning channel, kept only so the client can recover an answer a
     * model routed there instead of into `content` (#323). Never the preferred
     * source: the client reads it only when `assistantContent()` is empty. Held
     * to its tail, and — unlike the answer — never charged to the size cap.
     */
    public function reasoningContent(): ?string
    {
        if (!$this->sawStreamEvent) {
            return $this->envelopeFields()['reasoning'];
        }

        return '' === $this->reasoning ? null : $this->reasoning;
    }

    /**
     * The blocking envelope's fields, decoded at most once per buffer
     * generation and shared by the three readers above.
     *
     * The client asks for the answer and the usage on every chunk, and here
     * both come from the same whole-body decode. Without the memo this shape
     * would pay one decode per field per chunk — the parse cost #327 removed
     * from the streaming shape and #409 must not reintroduce here.
     *
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsage}
     */
    private function envelopeFields(): array
    {
        if (null === $this->envelopeFields || $this->envelopeFieldsGeneration !== $this->bufferGeneration) {
            $this->envelopeFieldsGeneration = $this->bufferGeneration;
            $this->envelopeFields = $this->decoder->envelope($this->envelope . $this->pendingLine);
        }

        return $this->envelopeFields;
    }

    private function readLine(string $line): void
    {
        if (str_starts_with($line, 'data:')) {
            $this->startStreaming();
            $this->readEvent($line);

            return;
        }

        // Before the first event the shape is still open, so a line could
        // belong to a blocking envelope. After it, anything that is not an
        // event is SSE noise (keep-alive comments, blank separators).
        if (!$this->sawStreamEvent) {
            $this->envelope .= $line . "\n";
        }
    }

    private function startStreaming(): void
    {
        if ($this->sawStreamEvent) {
            return;
        }

        $this->sawStreamEvent = true;
        // Whatever preceded the first event was preamble, not an envelope.
        // Dropping it keeps a chatty provider's keep-alive comments from
        // being carried for the length of the call.
        $this->envelope = '';
    }

    private function readEvent(string $line): void
    {
        $payload = trim(substr($line, \strlen('data:')));

        if ('' === $payload || '[DONE]' === $payload) {
            return;
        }

        $event = $this->decoder->streamEvent($payload);
        $this->answer .= $event['content'] ?? '';
        $this->appendReasoning($event['reasoning'] ?? '');
        $this->finishReason = $event['finishReason'] ?? $this->finishReason;
        $this->usage = $event['usage'] ?? $this->usage;
    }

    /**
     * Appends to the reasoning tail and trims it back to the bound from the
     * front, so the buffer keeps the end — where the answer sits — however
     * long the thinking phase runs.
     */
    private function appendReasoning(string $fragment): void
    {
        $this->reasoning .= $fragment;

        // Keep only the tail: a reasoning phase can stream up to the wire cap,
        // but the answer sits at its end (#323), and it must never be charged
        // to the answer's own cap (#320) — so this buffer bounds itself.
        if (\strlen($this->reasoning) > self::REASONING_TAIL_LIMIT) {
            $this->reasoning = substr($this->reasoning, -self::REASONING_TAIL_LIMIT);
        }
    }

    /**
     * A stream cut short — or one whose last event simply arrives without its
     * closing newline — leaves a final event in the buffer that the line loop
     * never saw. Decoding it here salvages that last delta; a genuinely
     * truncated payload does not decode and contributes nothing.
     */
    private function trailingEventContent(): string
    {
        if (!str_starts_with($this->pendingLine, 'data:')) {
            return '';
        }

        $payload = trim(substr($this->pendingLine, \strlen('data:')));

        return $this->decoder->deltaContent($payload) ?? '';
    }
}
