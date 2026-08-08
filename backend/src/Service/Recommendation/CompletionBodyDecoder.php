<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one accumulated /chat/completions response body into the assistant
 * content, whichever shape the provider chose: the SSE transcript a
 * `stream: true` request produces, or the blocking JSON envelope from a
 * provider that ignores the flag. Null means the body carried no completion.
 */
final readonly class CompletionBodyDecoder
{
    public function assistantContent(string $body): ?string
    {
        // A raw newline cannot occur inside a JSON string (it must be escaped),
        // so a line-initial "data:" cannot appear in a blocking envelope: this
        // detection cannot misread one shape as the other.
        if (1 === preg_match('/^data:/m', $body)) {
            return $this->joinedStreamDeltas($body);
        }

        return $this->choiceContent($body, 'message');
    }

    private function joinedStreamDeltas(string $body): ?string
    {
        $deltas = [];

        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, \strlen('data:')));

            if ('' === $payload || '[DONE]' === $payload) {
                continue;
            }

            $delta = $this->choiceContent($payload, 'delta');

            if (null !== $delta) {
                $deltas[] = $delta;
            }
        }

        return [] === $deltas ? null : implode('', $deltas);
    }

    /**
     * Both shapes carry the answer at `choices[0].<key>.content` and diverge
     * only in that one key: an SSE event names it `delta`, a whole envelope
     * `message`. Every step is guarded because the provider is untrusted —
     * any of them can be absent or the wrong type.
     */
    private function choiceContent(string $json, string $choiceKey): ?string
    {
        $decoded = json_decode($json, true);
        $choices = \is_array($decoded) ? ($decoded['choices'] ?? null) : null;
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;
        $answer = \is_array($firstChoice) ? ($firstChoice[$choiceKey] ?? null) : null;
        $content = \is_array($answer) ? ($answer['content'] ?? null) : null;

        return \is_string($content) ? $content : null;
    }
}
