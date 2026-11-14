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
 * Any `[src]` element an embed provider claims, not only `<iframe>` (a proprietary player element carries the same
 * attribute); the only route for an embed readability removes. It also sees sidebar embeds, so the caller drops
 * these whenever the body recovered media of its own.
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
