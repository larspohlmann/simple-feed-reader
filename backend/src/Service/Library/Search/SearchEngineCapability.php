<?php

declare(strict_types=1);

namespace App\Service\Search;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether a search engine is configured, and its url and key: the one place MEILISEARCH_URL/KEY are read. Both are
 * trimmed and isConfigured() reads the trimmed url, so a whitespace-only value in a hand-edited .env.local is empty.
 */
final readonly class SearchEngineCapability
{
    public function __construct(
        #[Autowire('%env(MEILISEARCH_URL)%')]
        private string $rawUrl,
        #[Autowire('%env(MEILISEARCH_KEY)%')]
        private string $rawKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->url();
    }

    public function url(): string
    {
        return trim($this->rawUrl);
    }

    public function key(): string
    {
        return trim($this->rawKey);
    }
}
