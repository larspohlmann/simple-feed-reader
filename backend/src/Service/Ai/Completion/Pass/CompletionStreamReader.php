<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Pass;

use App\Service\Ai\Completion\CompletionBodyDecoder;
use App\Service\Ai\Completion\Model\CompletionUsageModel;

/**
 * Reads one /chat/completions response as it arrives and keeps only the answer: each event is dropped once decoded,
 * so a reasoning model's megabytes of thinking are never retained or charged to the answer's cap.
 */
final class CompletionStreamReader
{
    /**
     * The reasoning channel keeps only this much tail, where LM Studio's models put the answer. It bounds itself and
     * is never charged to retainedBytes().
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
     * it carries none, so a later null must never erase it.
     */
    private ?CompletionUsageModel $usage = null;

    /**
     * Counts buffer changes (only consume() makes them), keying the shared envelope decode. Length is no stand-in: a
     * CRLF break leaves exactly as many bytes as it removes.
     */
    private int $bufferGeneration = 0;

    /**
     * The last blocking-envelope decode and the generation it was taken at.
     *
     * @var array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsageModel}|null
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
     * Why the provider stopped (`length`: `max_tokens`; `stop`: a natural end), null until an event says so. Once
     * said it stays, so a trailing usage-only event cannot erase it.
     */
    public function finishReason(): ?string
    {
        if (!$this->sawStreamEvent) {
            return $this->envelopeFields()['finishReason'];
        }

        return $this->finishReason;
    }

    /**
     * Whether `max_tokens` stopped the provider. Judged here beside the other provider dialects, so an endpoint that
     * spells the ceiling differently is a one-line change.
     */
    public function hitTokenCeiling(): bool
    {
        return 'length' === $this->finishReason();
    }

    /**
     * What the provider says this call consumed, null until it says so. No salvage from an unterminated last event:
     * that costs a decode per chunk, and the usage message is always followed by `data: [DONE]`.
     */
    public function usage(): ?CompletionUsageModel
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
     * Answer bytes held, for the `max_tokens`-derived bound. Zero on the blocking shape, whose buffer is not an answer
     * until it parses; retainedBytes() bounds that shape.
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

    /** The reasoning channel's tail, which the client reads only when assistantContent() is empty. */
    public function reasoningContent(): ?string
    {
        if (!$this->sawStreamEvent) {
            return $this->envelopeFields()['reasoning'];
        }

        return '' === $this->reasoning ? null : $this->reasoning;
    }

    /**
     * The blocking envelope's fields, decoded at most once per buffer generation: the client reads the answer and the
     * usage on every chunk, and a decode per field would re-parse the whole body each time.
     *
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsageModel}
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

        if (\strlen($this->reasoning) > self::REASONING_TAIL_LIMIT) {
            $this->reasoning = substr($this->reasoning, -self::REASONING_TAIL_LIMIT);
        }
    }

    /**
     * Salvages a last event that arrived without its closing newline, as a cut-short stream leaves it; a truncated
     * payload does not decode and adds nothing.
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
