<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\EmbedTarget;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageFurniture;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A player some sites build client-side leaves no embeddable node in the
 * fetched page — only a URL in an inline script (Lion's Roar's
 * `var videoData = {"url":"https://vimeo.com/<id>/"}`). Every https URL an
 * inline script names is run through the embed allow-list; only a provider
 * match becomes a candidate, so a `player.js` asset or an analytics ping is
 * ignored. Scripts under page chrome are skipped, so a related-video widget in
 * a sidebar or footer never becomes the article's hero.
 *
 * No prose anchor: the script sits at the page's end, far from the empty mount
 * the player fills, so the candidate is top-placed as the hero rather than
 * dragged behind the last paragraph.
 */
#[AsTaggedItem(priority: 50)]
final readonly class ScriptEmbedSource implements MediaCandidateSourceInterface
{
    private const string URL_PATTERN = '#https://[^"\'\s\\\\<>]+#i';

    public function __construct(private EmbedProviders $providers)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $found = [];
        foreach ($document->querySelectorAll('script') as $script) {
            if (PageFurniture::holds($script)) {
                continue;
            }
            foreach ($this->embedTargets($script->textContent ?? '') as $target) {
                $found[$target->url] ??= new MediaCandidate(
                    MediaKind::Embed,
                    $target->url,
                    $target->posterUrl,
                    $target->label,
                );
            }
        }

        return array_values($found);
    }

    /** @return list<EmbedTarget> */
    private function embedTargets(string $scriptText): array
    {
        // A URL can sit inside a JSON string nested in the script; one decode
        // turns a stray "&quot;" back into a quote so the pattern stops at it.
        $decoded = html_entity_decode($scriptText, \ENT_QUOTES | \ENT_HTML5);
        preg_match_all(self::URL_PATTERN, $decoded, $matches);
        $targets = [];
        foreach ($matches[0] as $url) {
            $target = $this->providers->resolve($url);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }
}
