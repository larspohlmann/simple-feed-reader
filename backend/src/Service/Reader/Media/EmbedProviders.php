<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\EmbedProvider\EmbedProviderInterface;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The embed allow-list: a URL no provider claims resolves to null, and the sanitizer then drops its markup. */
final readonly class EmbedProviders
{
    /** @param iterable<EmbedProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.embed_provider')]
        private iterable $providers,
    ) {
    }

    public function resolve(string $url): ?EmbedTargetModel
    {
        foreach ($this->providers as $provider) {
            $normalized = $provider->matches($url) ? $provider->normalize($url) : null;
            if ($normalized !== null) {
                return new EmbedTargetModel($normalized, $provider->poster($url), $provider->label());
            }
        }

        return null;
    }

    /**
     * Every provider's frame pattern, sorted for a stable dump.
     *
     * @return list<string>
     */
    public function framePatterns(): array
    {
        return array_keys($this->kindsByFramePattern());
    }

    /** Every frame pattern with the kind of player it embeds, as the reader client's committed allow-list file. */
    public function allowlistJson(): string
    {
        $entries = [];
        foreach ($this->kindsByFramePattern() as $pattern => $kind) {
            $entries[] = ['pattern' => $pattern, 'kind' => $kind->value];
        }

        return json_encode($entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }

    /** The case-insensitive regex readability keeps an in-body frame by, built from every provider's sourceHosts(). */
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

    /** @return array<string, EmbedKind> sorted by pattern */
    private function kindsByFramePattern(): array
    {
        $kinds = [];
        foreach ($this->providers as $provider) {
            $kinds[$provider->framePattern()] = $provider->kind();
        }
        ksort($kinds, \SORT_STRING);

        return $kinds;
    }
}
