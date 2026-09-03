<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Knows where a /chat/completions answer sits inside the provider's JSON, in either
 * shape: the whole envelope a blocking request returns, or one event of the SSE stream
 * a `stream: true` request produces. Framing is not its business — CompletionStreamReader
 * owns that and hands single payloads here. Null means the JSON carried no content.
 */
final readonly class CompletionBodyDecoder
{
    /**
     * Every field of one blocking envelope from a single decode: the answer, the
     * reasoning channel a model may have routed the answer into instead (#323), and the
     * provider's own usage report. The mirror of streamEvent() below: a provider that
     * ignores `stream: true` has its whole buffer re-read on every chunk, so reading
     * every field off it must cost one decode, not one apiece.
     *
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsage}
     */
    public function envelope(string $body): array
    {
        $root = $this->decodeRoot($body);
        $choice = $this->firstChoiceIn($root);

        return [
            'content' => $this->contentOf($choice, 'message'),
            'reasoning' => $this->reasoningOf($choice, 'message'),
            // Both shapes stamp it on the choice, but this shape never decoded
            // it, so hitTokenCeiling() stayed false for a provider that ignores
            // `stream: true` and the runaway classifier could not fire (#437).
            'finishReason' => $this->finishReasonOf($choice),
            'usage' => $this->usageIn($root),
        ];
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
     * Every field of one stream event from a single decode. The reader reads an event's
     * answer fragment, finish reason and usage report together, so decoding once here
     * halves the parse work over a reasoning model's thousands of thinking events (#327).
     *
     * `usage` is the provider's own accounting, which OpenAI-compatible endpoints send in
     * the last message of a streamed reply — the one whose `choices` is empty, which is
     * why nothing here read it before (#409).
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
     * One counter of the usage report. Absent, non-numeric or negative reads 0: the
     * provider is untrusted, and a token count it did not send is one it did not spend.
     * A negative one reads 0 rather than passing on, because these counters are banked
     * with SQL arithmetic onto a running per-run total — a below-zero reading would
     * subtract from calls that really happened, indistinguishable from a cheaper run.
     *
     * @param array<mixed> $fields
     */
    private function intField(array $fields, string $key): int
    {
        $value = $fields[$key] ?? null;

        return \is_int($value) && $value >= 0 ? $value : 0;
    }

    /**
     * The price, converted from the provider's float credits to the integer nano-credits
     * downstream stores. Null — not zero — when the provider reported no price: zero
     * claims the call was free, a different statement from unpriced (a local model).
     *
     * A number the provider cannot have meant is refused rather than clamped: an
     * unbelievable price is no reading at all, and null already says that. A negative
     * price would subtract from the account's all-time spend, and a huge one overflows
     * the (int) cast — undefined for an out-of-range float — corrupting the total once
     * and making BIGINT reject the next write.
     *
     * @param array<mixed> $usage
     */
    private function nanoCreditsIn(array $usage): ?int
    {
        $cost = $usage['cost'] ?? null;

        if (!\is_float($cost) && !\is_int($cost)) {
            return null;
        }

        if ($cost < 0 || !is_finite((float) $cost)) {
            return null;
        }

        $nanoCredits = round((float) $cost * 1_000_000_000);

        // Compared as a float, and with >=, because (float) PHP_INT_MAX rounds
        // up to 2**63 — one past the largest int there is.
        return $nanoCredits >= (float) \PHP_INT_MAX ? null : (int) $nanoCredits;
    }
}
