<?php

declare(strict_types=1);

namespace App\Service\Search;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether a search engine is configured for this instance, and what to talk
 * to it with — the single source of truth MEILISEARCH_URL/MEILISEARCH_KEY
 * answer.
 *
 * Follows App\Service\Mail\MailCapability's shape: a DEPLOY-TIME fact read
 * once via an inline #[Autowire], no services.yaml entry needed. Before this
 * class existed, MeilisearchIndex, EntrySearchWithFallback and
 * SearchReindexCommand each bound the same env var and re-derived "is an
 * engine configured?" from their own copy with a bare `'' === $url` — three
 * defensive comments arguing these were different questions was the tell
 * that they were not.
 *
 * url() and key() are trimmed, and isConfigured() is defined on the trimmed
 * url — never the raw env value — so the check and what MeilisearchIndex
 * sends can never disagree. Strato is configured by hand-editing .env.local;
 * a whitespace-only or stray-quoted value used to read as "configured" under
 * a bare `'' === $url` check, so every search paid the full timeout against
 * a malformed URL and logged a warning, instead of going straight to the
 * database.
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
