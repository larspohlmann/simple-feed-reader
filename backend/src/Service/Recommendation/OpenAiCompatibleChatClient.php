<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends `POST {baseUrl}/chat/completions`, the one call a tick makes to turn
 * a prompt into a ranking.
 *
 * The caps are not an SSRF boundary — see ProviderCredentials for why there is
 * none — they keep one hostile or broken endpoint from holding a request open
 * or filling memory.
 */
final readonly class OpenAiCompatibleChatClient implements ChatCompletionClient
{
    // A ranking over a large batch can legitimately generate for minutes; this
    // is also why the tick endpoint performs exactly one call — the whole tick
    // must fit one FastCGI request. #312 will add streamed reads with stall
    // detection under this same interface.
    //
    // Public because it is a published bound, not an implementation detail:
    // WorkerPresence::FRESH_SECONDS has to outlast one call of this length or
    // the worker looks dead while it is merely thinking (#311).
    public const float TIMEOUT_SECONDS = 120.0;
    private const int MAXIMUM_RESPONSE_BYTES = 2_097_152;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $userAgent,
    ) {
    }

    public function complete(ProviderCredentials $credentials, string $model, array $messages): string
    {
        $body = $this->readBody($credentials, $model, $messages);
        $decoded = json_decode($body, true);

        if (!\is_array($decoded)) {
            throw new ProviderUnreachableException('That provider answered without a completion.');
        }

        $content = $this->assistantContent($decoded);

        if (!\is_string($content)) {
            throw new ProviderUnreachableException('That provider answered without a completion.');
        }

        return $content;
    }

    /** @param array<mixed> $decoded */
    private function assistantContent(array $decoded): mixed
    {
        $choices = $decoded['choices'] ?? null;
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;
        $message = \is_array($firstChoice) ? ($firstChoice['message'] ?? null) : null;

        return \is_array($message) ? ($message['content'] ?? null) : null;
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function readBody(ProviderCredentials $credentials, string $model, array $messages): string
    {
        try {
            $response = $this->request($credentials, $model, $messages);
            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw new CredentialsRejectedException('That provider refused the API key.');
            }

            if ($status >= 300) {
                throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
            }

            return $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new ProviderUnreachableException('That address did not answer.', 0, $e);
        }
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function request(ProviderCredentials $credentials, string $model, array $messages): ResponseInterface
    {
        return $this->httpClient->request('POST', $credentials->baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials->apiKey,
                'Accept' => 'application/json',
                // Refuse transparent compression so the wire cap below also bounds
                // the buffered body — gzip would otherwise let a small reply
                // decompress unbounded after the cap has already passed it. Same
                // reasoning as ConcurrentFeedFetcher::headers().
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
            ],
            'json' => [
                'model' => $model,
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
            // Capped on the wire, the one size-cap mechanism this codebase has
            // (ConcurrentFeedFetcher::send(), HtmlPageFetcher, CatalogFaviconFetcher):
            // a provider answering with gigabytes is refused as the bytes arrive,
            // rather than truncated into a body that can only fail to parse. The
            // transport re-reports the aborted download as its own failure, which
            // readBody() translates back into this domain's refusal.
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAXIMUM_RESPONSE_BYTES) {
                    throw new ProviderUnreachableException(sprintf(
                        'That provider answered with more than %d bytes.',
                        self::MAXIMUM_RESPONSE_BYTES,
                    ));
                }
            },
        ]);
    }
}
