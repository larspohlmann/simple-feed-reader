<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A publisher's own schema.org markup. `Service/Scraper/JsonLdArticles.php`
 * walks the same script blocks but exposes only fully modelled Article cards
 * built from an already-decoded array via an injected PageUrls — nothing this
 * layer could reuse for a bare media URL, so this is its own walker.
 *
 * schema.org nests `contentUrl`/`embedUrl` under many shapes (VideoObject,
 * AudioObject, an Article's "video" property, …); rather than model each one,
 * every string value in the decoded tree is inspected under those two keys.
 * MediaUrlKind refuses anything that is not playable, which is what makes the
 * broad search safe — an ImageObject's contentUrl is just discarded.
 */
#[AsTaggedItem(priority: 100)]
final readonly class JsonLdMediaSource implements MediaCandidateSourceInterface
{
    private const array URL_KEYS = ['contentUrl', 'embedUrl'];

    public function __construct(
        private MediaUrlKind $mediaUrlKind,
        private EmbedProviders $embedProviders,
    ) {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $found = [];
        foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $script) {
            foreach ($this->urlsIn($script->textContent ?? '') as $url) {
                $candidate = $this->toCandidate($url);
                if ($candidate !== null) {
                    $found[$candidate->url] = $candidate;
                }
            }
        }

        return array_values($found);
    }

    /** @return list<string> */
    private function urlsIn(string $jsonLd): array
    {
        $decoded = json_decode($jsonLd, true);

        return \is_array($decoded) ? $this->collect($decoded) : [];
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<string>
     */
    private function collect(array $node): array
    {
        $urls = [];
        foreach ($node as $key => $value) {
            if (\is_string($value) && \in_array($key, self::URL_KEYS, true)) {
                $urls[] = $value;
            } elseif (\is_array($value)) {
                array_push($urls, ...$this->collect($value));
            }
        }

        return $urls;
    }

    private function toCandidate(string $url): ?MediaCandidate
    {
        $kind = $this->mediaUrlKind->of($url);
        if ($kind === null) {
            return null;
        }
        if ($kind !== MediaKind::Embed) {
            return new MediaCandidate($kind, $url);
        }

        $target = $this->embedProviders->resolve($url);
        if ($target === null) {
            return null;
        }

        return new MediaCandidate(MediaKind::Embed, $target->url, $target->posterUrl, $target->label);
    }
}
