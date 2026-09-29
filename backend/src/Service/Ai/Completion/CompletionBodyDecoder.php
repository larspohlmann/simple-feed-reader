<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion;

use App\Service\Ai\Completion\Model\CompletionUsageModel;

/**
 * Finds a /chat/completions answer in the provider's JSON, a blocking envelope or one SSE event; the framing is
 * CompletionStreamReader's. Null means the JSON carried no content.
 */
final readonly class CompletionBodyDecoder
{
    /**
     * Every field of one blocking envelope from a single decode: a provider that ignores `stream: true` has its whole
     * buffer re-read on every chunk, so the fields must cost one decode, not one apiece.
     *
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsageModel}
     */
    public function envelope(string $body): array
    {
        $root = $this->decodeRoot($body);
        $choice = $this->firstChoiceIn($root);

        return [
            'content' => $this->contentOf($choice, 'message'),
            'reasoning' => $this->reasoningOf($choice, 'message'),
            'finishReason' => $this->finishReasonOf($choice),
            'usage' => $this->usageIn($root),
        ];
    }

    public function deltaContent(string $payload): ?string
    {
        return $this->contentOf($this->firstChoice($payload), 'delta');
    }

    /** Why generation stopped (`length`: `max_tokens` cut it; `stop`: a natural end); null while still streaming. */
    public function finishReason(string $json): ?string
    {
        return $this->finishReasonOf($this->firstChoice($json));
    }

    /**
     * Every field of one stream event from a single decode, over a reasoning model's thousands of thinking events.
     * `usage` arrives at the root of the stream's last message, the one whose `choices` is empty.
     *
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsageModel}
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
     * The payload as an array, or null when it is not JSON; decoded once and shared by the choice and usage reads.
     *
     * @return array<mixed>|null
     */
    private function decodeRoot(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The first choice, or null when any step of the untrusted shape is missing or mistyped. The stream's final usage
     * message carries `choices: []`, so null is routine.
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
    private function usageIn(?array $root): ?CompletionUsageModel
    {
        $usage = null === $root ? null : ($root['usage'] ?? null);

        if (!\is_array($usage)) {
            return null;
        }

        return new CompletionUsageModel(
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
     * One usage counter; absent, non-integer or negative reads 0. A negative one would subtract from the per-run total
     * it is banked onto with SQL arithmetic.
     *
     * @param array<mixed> $fields
     */
    private function intField(array $fields, string $key): int
    {
        $value = $fields[$key] ?? null;

        return \is_int($value) && $value >= 0 ? $value : 0;
    }

    /**
     * The price in integer nano-credits; null, not zero (a claim of "free"), when unpriced. A negative, non-finite or
     * out-of-range cost is refused as null, never clamped: it would corrupt the all-time spend or overflow the cast.
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
