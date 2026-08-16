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
 * once via an inline #[Autowire], needing no services.yaml entry of its own.
 * Before this class existed, MeilisearchIndex, EntrySearchWithFallback and
 * SearchReindexCommand each bound the same env var a second and third time
 * and re-derived "is an engine configured?" from their own copy with a bare
 * `'' === $url` — three defensive services.yaml comments arguing these were
 * different questions was the tell that they were not.
 *
 * url() and key() are trimmed, and isConfigured() is defined in terms of the
 * trimmed url — never the raw env value — so the configured-check and what
 * MeilisearchIndex actually sends can never disagree. This project's
 * shared-hosting deployment (Strato) is configured by hand-editing
 * .env.local; a whitespace-only value or one carrying a stray quote used to
 * read as "configured" under a bare `'' === $url` check, which meant every
 * search paid MeilisearchIndex's full timeout against a malformed URL and
 * logged a warning, instead of going straight to the database.
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
