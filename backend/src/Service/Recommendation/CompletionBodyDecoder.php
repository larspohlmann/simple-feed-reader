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
     * The provider's own accounting for the call, which OpenAI-compatible
     * endpoints send in the last message of a streamed reply — the message
     * whose `choices` is empty, which is why nothing here read it before
     * (#409). A blocking envelope carries the same object at its root, so one
     * reader covers both shapes.
     */
    public function usage(string $json): ?CompletionUsage
    {
        return $this->usageIn($this->decodeRoot($json));
    }

    /**
     * Every field of one stream event from a single decode. The reader reads
     * an event's answer fragment, its finish reason and the provider's usage
     * report together, so decoding once here — rather than once per field —
     * halves the parse work over a reasoning model's thousands of thinking
     * events (#327).
     *
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsage}
     */
    public function streamEvent(string $payload): array
    {
        $root = $this->decodeRoot($payload);
        $choice = $this->firstChoiceIn($root);

        return [
            'content' => $this->contentOf($choice, 'delta'),
            'reasoning' => $this->reasoningOf($choice, 'delta'),
            'finishReason' => $this->finishReasonOf($choice),
            'usage' => $this->usageIn($root),
        ];
    }

    /**
     * The payload as an array, or null when it is not JSON at all. Decoded once
     * per payload and shared: `streamEvent()` reads the choice fields and the
     * root-level usage object off the same decode, and a second decode per
     * event is exactly the parse cost #327 removed.
     *
     * @return array<mixed>|null
     */
    private function decodeRoot(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The first choice as an array, or null when the shape is wrong. Every
     * step is guarded because the provider is untrusted — any of them can be
     * absent or the wrong type. The final usage message of a stream carries
     * `choices: []`, so null here is routine, not a fault.
     *
     * @param array<mixed>|null $root
     *
     * @return array<mixed>|null
     */
    private function firstChoiceIn(?array $root): ?array
    {
        $choices = null === $root ? null : ($root['choices'] ?? null);
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;

        return \is_array($firstChoice) ? $firstChoice : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function firstChoice(string $json): ?array
    {
        return $this->firstChoiceIn($this->decodeRoot($json));
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

    /**
     * @param array<mixed>|null $root
     */
    private function usageIn(?array $root): ?CompletionUsage
    {
        $usage = null === $root ? null : ($root['usage'] ?? null);

        if (!\is_array($usage)) {
            return null;
        }

        return new CompletionUsage(
            $this->intField($usage, 'prompt_tokens'),
            $this->intField($usage, 'completion_tokens'),
            $this->intField($this->detailsOf($usage, 'completion_tokens_details'), 'reasoning_tokens'),
            $this->intField($this->detailsOf($usage, 'prompt_tokens_details'), 'cached_tokens'),
            $this->nanoCreditsIn($usage),
        );
    }

    /**
     * A nested detail object of the usage report, or an empty array when the
     * provider sent none — the two detail objects are optional, and a provider
     * that omits them reports zero of what they count, not an unknown.
     *
     * @param array<mixed> $usage
     *
     * @return array<mixed>
     */
    private function detailsOf(array $usage, string $key): array
    {
        $details = $usage[$key] ?? null;

        return \is_array($details) ? $details : [];
    }

    /**
     * One counter of the usage report. Absent or non-numeric reads 0: the
     * provider is untrusted, and a token count it did not send is one it did
     * not spend as far as anything here can tell.
     *
     * @param array<mixed> $fields
     */
    private function intField(array $fields, string $key): int
    {
        $value = $fields[$key] ?? null;

        return \is_int($value) ? $value : 0;
    }

    /**
     * The price, converted from the provider's float credits to the integer
     * nano-credits everything downstream stores. Null — not zero — when the
     * provider reported no price: zero claims the call was free, which is a
     * different statement from unpriced (a local model, say).
     *
     * @param array<mixed> $usage
     */
    private function nanoCreditsIn(array $usage): ?int
    {
        $cost = $usage['cost'] ?? null;

        if (!\is_float($cost) && !\is_int($cost)) {
            return null;
        }

        return (int) round($cost * 1_000_000_000);
    }
}
