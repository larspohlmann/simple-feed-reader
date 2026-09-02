<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageTextBlocks;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Host-agnostic: any `[src]` element on the page an embed provider claims —
 * not only `<iframe>`, because a proprietary player element (heise's
 * `<a-iframe>`) carries the same attribute. This is also the only route for
 * an embed readability removes before the body cleaner can see it (5
 * Magazine's SoundCloud player).
 *
 * A whole-page scan also sees sidebar and related-teaser embeds. The caller
 * suppresses these whenever the body recovered its own, so nothing outside the
 * article is inserted while the article has media of its own.
 */
#[AsTaggedItem(priority: 80)]
final readonly class PageEmbedSource implements MediaCandidateSourceInterface
{
    public function __construct(private EmbedProviders $providers)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $blocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($document->querySelectorAll('[src]') as $element) {
            $target = $this->providers->resolve($element->getAttribute('src') ?? '');
            if ($target !== null) {
                $found[$target->url] ??= new MediaCandidate(
                    MediaKind::Embed,
                    $target->url,
                    $target->posterUrl,
                    $target->label,
                    $blocks->before($element),
                );
            }
        }

        return array_values($found);
    }
}
