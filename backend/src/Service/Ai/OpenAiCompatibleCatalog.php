<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads `GET {baseUrl}/models`, the one call every OpenAI-compatible provider
 * answers the same way.
 *
 * The caps are not an SSRF boundary — see ProviderCredentials for why there is
 * none — they keep one hostile or broken endpoint from holding a request open
 * or filling memory.
 */
final readonly class OpenAiCompatibleCatalog implements ModelCatalog
{
    private const float TIMEOUT_SECONDS = 10.0;
    private const int MAXIMUM_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $userAgent,
    ) {
    }

    public function listModels(ProviderCredentials $credentials): array
    {
        $body = $this->readBody($credentials);
        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || !isset($decoded['data']) || !\is_array($decoded['data'])) {
            throw new ProviderUnreachableException('That address answered, but not with a model list.');
        }

        $models = $this->identifiers($decoded['data']);

        if ([] === $models) {
            throw new ProviderUnreachableException('That provider offers no models.');
        }

        sort($models);

        return $models;
    }

    private function readBody(ProviderCredentials $credentials): string
    {
        try {
            $response = $this->httpClient->request('GET', $credentials->baseUrl . '/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials->apiKey,
                    'Accept' => 'application/json',
                    'User-Agent' => $this->userAgent,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);

            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw new CredentialsRejectedException('That provider refused the API key.');
            }

            if ($status >= 300) {
                throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
            }

            return $this->boundedContent($response);
        } catch (ExceptionInterface $e) {
            throw new ProviderUnreachableException('That address did not answer.', 0, $e);
        }
    }

    /**
     * Reads at most the cap. Streaming chunk-by-chunk rather than
     * `getContent()`: a provider that answers with gigabytes must cost one
     * megabyte of memory, not all of it.
     */
    private function boundedContent(ResponseInterface $response): string
    {
        $content = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $content .= $chunk->getContent();

            if (\strlen($content) >= self::MAXIMUM_RESPONSE_BYTES) {
                break;
            }
        }

        return $content;
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<string>
     */
    private function identifiers(array $entries): array
    {
        $models = [];

        foreach ($entries as $entry) {
            if (\is_array($entry) && isset($entry['id']) && \is_string($entry['id']) && '' !== $entry['id']) {
                $models[] = $entry['id'];
            }
        }

        return $models;
    }
}
