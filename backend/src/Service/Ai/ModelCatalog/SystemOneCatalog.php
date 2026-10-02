<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ModelDescriptorModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offers TypeSafe's `jev-latest` alias wherever `{base}/systemone` exists: OpenRouter's `/models` does not list it and
 * TypeSafe's own is not OpenAI-shaped. An alias, never a pinned version; the run log records which one answered.
 */
#[AutoconfigureTag(CompositeModelCatalog::MEMBER_TAG, ['priority' => 0])]
final readonly class SystemOneCatalog implements ModelCatalogInterface
{
    /** One System One request, state and every question together, as OpenRouter documents it. */
    public const int CONTEXT_WINDOW_TOKENS = 32_000;

    /** OpenRouter refuses `jev-preview`, and TypeSafe documents only `jev-latest`. */
    private const array MODEL_IDS = ['jev-latest'];

    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $userAgent,
    ) {
    }

    public function listModels(ProviderCredentialsModel $credentials): array
    {
        self::assertTheEndpointAnswered($this->probeStatus($credentials));

        return array_map(
            static fn (string $id): ModelDescriptorModel => new ModelDescriptorModel($id, self::CONTEXT_WINDOW_TOKENS),
            self::MODEL_IDS,
        );
    }

    /**
     * The probe's body is empty, which a real endpoint refuses with a 4xx and bills nothing for. A 2xx means a server
     * that answers every path (LM Studio does), a 404 or 405 no such route, a 5xx nothing to tell.
     */
    private static function assertTheEndpointAnswered(int $status): void
    {
        if (401 === $status || 403 === $status) {
            throw new CredentialsRejectedException('That provider refused the API key.');
        }

        if ($status < 400 || $status >= 500 || 404 === $status || 405 === $status) {
            throw new ProviderUnreachableException('That address offers no System One endpoint.');
        }
    }

    private function probeStatus(ProviderCredentialsModel $credentials): int
    {
        try {
            return $this->httpClient->request('POST', $credentials->baseUrl . '/systemone', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => $this->userAgent,
                    ...$credentials->authorizationHeaders(),
                ],
                'body' => '{}',
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ])->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new ProviderUnreachableException('That address did not answer.', 0, $exception);
        }
    }
}
