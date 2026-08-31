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
}
