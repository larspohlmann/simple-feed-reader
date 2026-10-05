<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Entity\ModelDescriptor;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Support\ResponseByteCap;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads `GET {baseUrl}/models?output_modalities=all`, which every OpenAI-compatible provider answers alike; the
 * parameter makes OpenRouter list its decision models beside the text ones. The caps are no SSRF boundary
 * (docs/security.md#ai-provider-endpoints); they stop one endpoint holding a request open or filling memory.
 */
#[AutoconfigureTag(CompositeModelCatalog::MEMBER_TAG, ['priority' => 10])]
final readonly class OpenAiCompatibleCatalog implements ModelCatalogInterface
{
    private const float TIMEOUT_SECONDS = 10.0;
    private const int MAXIMUM_RESPONSE_BYTES = 4 * 1024 * 1024;
    private const string TEXT_OUTPUT = 'text';
    private const array SCORING_OUTPUTS = ['decisions' => ScoringProtocol::SystemOne];

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
                throw CredentialsRejectedException::refusedKey();
            }

            if ($status >= 300) {
                throw ProviderUnreachableException::answeredWithStatus($status);
            }

            return $response->getContent();
        } catch (ExceptionInterface $exception) {
            throw ProviderUnreachableException::didNotAnswer($exception);
        }
    }

    private function request(ProviderCredentialsModel $credentials): ResponseInterface
    {
        return $this->httpClient->request('GET', $credentials->baseUrl . '/models?output_modalities=all', [
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
            'on_progress' => ResponseByteCap::onProgress(self::MAXIMUM_RESPONSE_BYTES),
        ]);
    }

    /**
     * One entry per id: an aggregating proxy (LiteLLM, a gateway) lists a model once per backend, and the frontend
     * keys its dropdown options by id.
     *
     * @param array<mixed> $entries
     *
     * @return list<ModelDescriptor> sorted by id, one entry per id
     */
    private function descriptors(array $entries): array
    {
        $byId = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry) || !isset($entry['id']) || !\is_string($entry['id']) || '' === $entry['id']) {
                continue;
            }
            if (!self::isUsableModel($entry)) {
                continue;
            }
            $byId[$entry['id']] ??= new ModelDescriptor(
                $entry['id'],
                self::reportedContextWindow($entry),
                self::scoringProtocolOf($entry),
            );
        }

        ksort($byId, SORT_STRING);

        return array_values($byId);
    }

    /**
     * A scoring model without a window is left out: its requests could not be budgeted.
     *
     * @param array<mixed> $entry
     */
    private static function isUsableModel(array $entry): bool
    {
        if (self::isTextModel($entry)) {
            return true;
        }

        return null !== self::scoringProtocolOf($entry) && null !== self::reportedContextWindow($entry);
    }

    /**
     * Text among the outputs, or no outputs reported at all (LM Studio, Ollama, OpenAI): what the plain listing offers.
     *
     * @param array<mixed> $entry
     */
    private static function isTextModel(array $entry): bool
    {
        $outputs = self::outputModalities($entry);

        return null === $outputs || \in_array(self::TEXT_OUTPUT, $outputs, true);
    }

    /** @param array<mixed> $entry */
    private static function scoringProtocolOf(array $entry): ?ScoringProtocol
    {
        $outputs = self::outputModalities($entry);
        if (null === $outputs || 1 !== \count($outputs) || !\is_string($outputs[0])) {
            return null;
        }

        return self::SCORING_OUTPUTS[$outputs[0]] ?? null;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return list<mixed>|null null when the entry reports none
     */
    private static function outputModalities(array $entry): ?array
    {
        $architecture = $entry['architecture'] ?? null;
        $outputs = \is_array($architecture) ? ($architecture['output_modalities'] ?? null) : null;

        return \is_array($outputs) ? array_values($outputs) : null;
    }

    /** @param array<mixed> $entry */
    private static function reportedContextWindow(array $entry): ?int
    {
        foreach (['context_length', 'max_context_length'] as $field) {
            if (isset($entry[$field]) && \is_int($entry[$field]) && $entry[$field] > 0) {
                return $entry[$field];
            }
        }

        return null;
    }
}
