<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Reads one /chat/completions response as it arrives and keeps only the
 * answer.
 *
 * Incremental rather than "accumulate the transcript, then decode it" (#320):
 * a reasoning model streams its whole thinking phase as SSE events whose
 * deltas carry no `content`, so the transcript runs to megabytes while the
 * answer is still empty. Retaining it put reasoning and per-token framing
 * under the same cap as the answer, and that cap — sized for a ~6 KB envelope
 * — killed calls that were working fine. Dropping every event once decoded
 * bounds memory by the answer, which is what the cap always meant to bound.
 *
 * Deliberately not readonly: one reader is one call's worth of state.
 */
final class CompletionStreamReader
{
    private string $pendingLine = '';
    private string $answer = '';
    private string $envelope = '';
    private bool $sawStreamEvent = false;
    private int $wireBytes = 0;
    private ?string $finishReason = null;

    public function __construct(private readonly CompletionBodyDecoder $decoder)
    {
    }

    public function consume(string $chunk): void
    {
        $this->wireBytes += \strlen($chunk);
        $this->pendingLine .= $chunk;

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
        return $this->finishReason;
    }

    /**
     * What this reader is actually holding on to. The client caps this rather
     * than the wire: it is the only part that grows without bound in memory.
     */
    public function retainedBytes(): int
    {
        return \strlen($this->answer) + \strlen($this->envelope) + \strlen($this->pendingLine);
    }

    public function assistantContent(): ?string
    {
        if (!$this->sawStreamEvent) {
            return $this->decoder->envelopeContent($this->envelope . $this->pendingLine);
        }

        $answer = $this->answer . $this->trailingEventContent();

        return '' === $answer ? null : $answer;
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
        $this->finishReason = $event['finishReason'] ?? $this->finishReason;
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
