<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The embed allow-list. A URL no provider claims resolves to null, and the
 * caller then leaves the markup alone — the sanitizer drops it as it does today.
 */
final readonly class EmbedProviders
{
    /** @param iterable<EmbedProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.embed_provider')]
        private iterable $providers,
    ) {
    }

    public function resolve(string $url): ?EmbedTarget
    {
        foreach ($this->providers as $provider) {
            $normalized = $provider->matches($url) ? $provider->normalize($url) : null;
            if ($normalized !== null) {
                return new EmbedTarget($normalized, $provider->poster($url), $provider->label());
            }
        }

        return null;
    }

    /**
     * Every provider's frame pattern, sorted for a stable dump (#1048).
     *
     * @return list<string>
     */
    public function framePatterns(): array
    {
        $patterns = [];
        foreach ($this->providers as $provider) {
            $patterns[] = $provider->framePattern();
        }
        sort($patterns);

        return $patterns;
    }

    /** The frame patterns as the reader client's committed allow-list file. */
    public function allowlistJson(): string
    {
        return json_encode($this->framePatterns(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * The delimited, case-insensitive regex that tells readability to keep an
     * in-body frame whose source host any provider claims — assembled from every
     * provider's `sourceHosts()` so the keep-list never drifts from the hosts the
     * reader actually renders (#1053).
     */
    public function videoEmbedRegex(): string
    {
        $hosts = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->sourceHosts() as $host) {
                $hosts[] = preg_quote($host, '#');
            }
        }

        return '#//(?:' . implode('|', $hosts) . ')#i';
    }
}
