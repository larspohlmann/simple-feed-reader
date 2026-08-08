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

        return $this->blockingEnvelopeContent($body);
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

            $event = json_decode($payload, true);
            $content = \is_array($event) ? $this->deltaContent($event) : null;

            if (\is_string($content)) {
                $deltas[] = $content;
            }
        }

        return [] === $deltas ? null : implode('', $deltas);
    }

    /** @param array<mixed> $event */
    private function deltaContent(array $event): mixed
    {
        $choices = $event['choices'] ?? null;
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;
        $delta = \is_array($firstChoice) ? ($firstChoice['delta'] ?? null) : null;

        return \is_array($delta) ? ($delta['content'] ?? null) : null;
    }

    private function blockingEnvelopeContent(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $choices = $decoded['choices'] ?? null;
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;
        $message = \is_array($firstChoice) ? ($firstChoice['message'] ?? null) : null;
        $content = \is_array($message) ? ($message['content'] ?? null) : null;

        return \is_string($content) ? $content : null;
    }
}
