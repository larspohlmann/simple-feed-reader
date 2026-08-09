<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Knows where a /chat/completions answer sits inside the provider's JSON,
 * in either of the two shapes: the whole envelope a blocking request returns,
 * or one event of the SSE stream a `stream: true` request produces.
 *
 * Framing is not this class's business — CompletionStreamReader owns that and
 * hands single payloads here. Null means the JSON carried no content.
 */
final readonly class CompletionBodyDecoder
{
    public function envelopeContent(string $body): ?string
    {
        return $this->contentOf($this->firstChoice($body), 'message');
    }

    public function deltaContent(string $payload): ?string
    {
        return $this->contentOf($this->firstChoice($payload), 'delta');
    }

    /**
     * Why the provider stopped generating, stamped on the choice itself:
     * `length` means `max_tokens` truncated the answer, `stop` a natural end.
     * Null while the choice is still streaming. Both shapes carry it in the
     * same place, so one reader covers stream events and whole envelopes alike.
     */
    public function finishReason(string $json): ?string
    {
        return $this->finishReasonOf($this->firstChoice($json));
    }

    /**
     * Both fields of one stream event from a single decode. The reader reads
     * an event's answer fragment and its finish reason together, so decoding
     * once here — rather than once per field — halves the parse work over a
     * reasoning model's thousands of thinking events (#327).
     *
     * @return array{content: ?string, finishReason: ?string}
     */
    public function streamEvent(string $payload): array
    {
        $choice = $this->firstChoice($payload);

        return [
            'content' => $this->contentOf($choice, 'delta'),
            'finishReason' => $this->finishReasonOf($choice),
        ];
    }

    /**
     * The first choice as an array, or null when the shape is wrong. Every
     * step is guarded because the provider is untrusted — any of them can be
     * absent or the wrong type. Shared so the decode-and-walk exists once.
     *
     * @return array<mixed>|null
     */
    private function firstChoice(string $json): ?array
    {
        $decoded = json_decode($json, true);
        $choices = \is_array($decoded) ? ($decoded['choices'] ?? null) : null;
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;

        return \is_array($firstChoice) ? $firstChoice : null;
    }

    /**
     * The answer sits at `<key>.content` and the two shapes diverge only in
     * that one key: an SSE event names it `delta`, a whole envelope `message`.
     *
     * @param array<mixed>|null $choice
     */
    private function contentOf(?array $choice, string $choiceKey): ?string
    {
        if (null === $choice) {
            return null;
        }

        $answer = \is_array($choice[$choiceKey] ?? null) ? $choice[$choiceKey] : null;
        $content = \is_array($answer) ? ($answer['content'] ?? null) : null;

        return \is_string($content) ? $content : null;
    }

    /** @param array<mixed>|null $choice */
    private function finishReasonOf(?array $choice): ?string
    {
        $reason = null === $choice ? null : ($choice['finish_reason'] ?? null);

        return \is_string($reason) ? $reason : null;
    }
}
