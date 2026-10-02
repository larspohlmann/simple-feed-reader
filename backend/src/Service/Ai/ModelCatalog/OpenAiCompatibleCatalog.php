<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ModelDescriptorModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads `GET {baseUrl}/models`, which every OpenAI-compatible provider answers alike. The caps are no SSRF boundary
 * (docs/security.md#ai-provider-endpoints); they stop one endpoint holding a request open or filling memory.
 */
final readonly class OpenAiCompatibleCatalog implements ModelCatalogInterface
{
    private const float TIMEOUT_SECONDS = 10.0;
    private const int MAXIMUM_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $userAgent,
    ) {
    }

    public function listModels(ProviderCredentialsModel $credentials): array
    {
        $body = $this->readBody($credentials);
        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || !isset($decoded['data']) || !\is_array($decoded['data'])) {
            throw new ProviderUnreachableException('That address answered, but not with a model list.');
        }

        $models = $this->descriptors($decoded['data']);

        if ([] === $models) {
            throw new ProviderUnreachableException('That provider offers no models.');
        }

        return $models;
    }

    private function readBody(ProviderCredentialsModel $credentials): string
    {
        try {
            $response = $this->request($credentials);
            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw new CredentialsRejectedException('That provider refused the API key.');
            }

            if ($status >= 300) {
                throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
            }

            return $response->getContent();
        } catch (ExceptionInterface $exception) {
            throw new ProviderUnreachableException('That address did not answer.', 0, $exception);
        }
    }

    private function request(ProviderCredentialsModel $credentials): ResponseInterface
    {
        return $this->httpClient->request('GET', $credentials->baseUrl . '/models', [
            'headers' => [
                'Accept' => 'application/json',
                // No transparent compression, so the wire cap below also bounds the decompressed body.
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
                ...$credentials->authorizationHeaders(),
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
            // Refused on the wire as the bytes arrive, not truncated into an unparseable body; readBody() reports the
            // aborted transfer as unreachable.
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

    /**
     * One entry per id: an aggregating proxy (LiteLLM, a gateway) lists a model once per backend, and the frontend
     * keys its dropdown options by id.
     *
     * @param array<mixed> $entries
     *
     * @return list<ModelDescriptorModel> sorted by id, one entry per id
     */
    private function descriptors(array $entries): array
    {
        $byId = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry) || !isset($entry['id']) || !\is_string($entry['id']) || '' === $entry['id']) {
                continue;
            }
            $byId[$entry['id']] ??= new ModelDescriptorModel($entry['id'], $this->reportedContextWindow($entry));
        }

        ksort($byId, SORT_STRING);

        return array_values($byId);
    }

    /** @param array<mixed> $entry */
    private function reportedContextWindow(array $entry): ?int
    {
        foreach (['context_length', 'max_context_length'] as $field) {
            if (isset($entry[$field]) && \is_int($entry[$field]) && $entry[$field] > 0) {
                return $entry[$field];
            }
        }

        return null;
    }
}
