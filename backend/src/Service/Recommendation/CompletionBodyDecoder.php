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
        return $this->choiceContent($body, 'message');
    }

    public function deltaContent(string $payload): ?string
    {
        return $this->choiceContent($payload, 'delta');
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
