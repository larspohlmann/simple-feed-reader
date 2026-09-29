<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\PageFurniture;
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
    public function __construct(private EmbedProviders $providers, private PageFurniture $furniture)
    {
    }

    public function find(RawPageModel $page): array
    {
        $found = [];
        foreach ($page->document->querySelectorAll('[src]') as $element) {
            if ($this->furniture->holds($element)) {
                continue;
            }
            $target = $this->providers->resolve($element->getAttribute('src') ?? '');
            if ($target !== null) {
                $found[$target->url] ??= new MediaCandidateModel(
                    MediaKind::Embed,
                    $target->url,
                    $target->posterUrl,
                    $target->label,
                    $page->blocks->before($element),
                );
            }
        }

        return array_values($found);
    }
}
