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
     * The reasoning channel of one blocking envelope: a reasoning model under
     * LM Studio that ignores `stream: true` puts its whole answer under
     * `message.reasoning_content` and leaves `content` empty (#323).
     */
    public function envelopeReasoning(string $body): ?string
    {
        return $this->reasoningOf($this->firstChoice($body), 'message');
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
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string}
     */
    public function streamEvent(string $payload): array
    {
        $choice = $this->firstChoice($payload);

        return [
            'content' => $this->contentOf($choice, 'delta'),
            'reasoning' => $this->reasoningOf($choice, 'delta'),
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
        return $this->stringField($this->answerOf($choice, $choiceKey), 'content');
    }

    /**
     * The same answer under its reasoning channel, which two providers spell
     * two ways: LM Studio `reasoning_content`, OpenRouter `reasoning`. Read as
     * a last resort — a model that answered under `content` is preferred.
     *
     * @param array<mixed>|null $choice
     */
    private function reasoningOf(?array $choice, string $choiceKey): ?string
    {
        $answer = $this->answerOf($choice, $choiceKey);

        return $this->stringField($answer, 'reasoning_content') ?? $this->stringField($answer, 'reasoning');
    }

    /**
     * The `delta` or `message` object that holds the answer fields, or null
     * when the choice does not carry one.
     *
     * @param array<mixed>|null $choice
     *
     * @return array<mixed>|null
     */
    private function answerOf(?array $choice, string $choiceKey): ?array
    {
        $answer = null === $choice ? null : ($choice[$choiceKey] ?? null);

        return \is_array($answer) ? $answer : null;
    }

    /**
     * One string field of the answer object, or null when it is absent or —
     * the provider being untrusted — not a string.
     *
     * @param array<mixed>|null $answer
     */
    private function stringField(?array $answer, string $field): ?string
    {
        $value = null === $answer ? null : ($answer[$field] ?? null);

        return \is_string($value) ? $value : null;
    }

    /** @param array<mixed>|null $choice */
    private function finishReasonOf(?array $choice): ?string
    {
        $reason = null === $choice ? null : ($choice['finish_reason'] ?? null);

        return \is_string($reason) ? $reason : null;
    }
}
