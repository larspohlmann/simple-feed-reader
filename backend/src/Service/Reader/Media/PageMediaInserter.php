<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaInsertionPlanModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Model\PageTextBlocksModel;
use App\Service\Reader\Model\ImageIdentityModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Places media the page offers but the extracted body lost: in place of a body `<img>` its poster matches, after
 * the prose block it followed, or at the top. `plan()` only classifies and `apply()` mutates, so
 * PageMediaPlacement decides the hero restore between them.
 */
final readonly class PageMediaInserter
{
    public function __construct(private MediaMarkup $markup)
    {
    }

    public function plan(HTMLDocument $document, ArticleMediaModel $media): MediaInsertionPlanModel
    {
        $root = $document->body;
        if ($root === null || $media->isEmpty()) {
            return new MediaInsertionPlanModel([], [], []);
        }

        return $this->classify($media, $this->reconcilableImages($root), PageTextBlocksModel::fromDocument($document));
    }

    public function apply(HTMLDocument $document, MediaInsertionPlanModel $plan, ?Element $belowHero = null): void
    {
        foreach ($plan->reconcilePairs as $pair) {
            $pair['image']->parentNode?->replaceChild($this->element($document, $pair['candidate']), $pair['image']);
        }

        // Reversed: two players anchored to one block each go right behind it,
        // so inserting last-first leaves them in source order.
        foreach (array_reverse($plan->anchoredPairs) as $pair) {
            $this->insertAfter($pair['block'], $this->element($document, $pair['candidate']));
        }

        $this->prependTopPlaced($document, $plan->topPlaced, $belowHero);
    }

    /** @param list<Element> $pool candidate body images, in document order */
    private function classify(
        ArticleMediaModel $media,
        array $pool,
        PageTextBlocksModel $bodyBlocks,
    ): MediaInsertionPlanModel {
        $reconciled = [];
        $anchored = [];
        $topPlaced = [];
        foreach ($media->candidates as $candidate) {
            $image = $candidate->posterUrl === null ? null : $this->claim($pool, $candidate->posterUrl);
            if ($image !== null) {
                $reconciled[] = ['image' => $image, 'candidate' => $candidate];
                continue;
            }
            $block = $candidate->precedingText === null ? null : $bodyBlocks->withText($candidate->precedingText);
            if ($block !== null) {
                $anchored[] = ['block' => $block, 'candidate' => $candidate];
                continue;
            }
            $topPlaced[] = $candidate;
        }

        return new MediaInsertionPlanModel($reconciled, $anchored, $topPlaced);
    }

    /**
     * @param list<Element> $pool mutated: a claimed image is removed so a
     *                             later candidate cannot also claim it
     */
    private function claim(array &$pool, string $posterUrl): ?Element
    {
        $posterIdentity = ImageIdentityModel::fromUrl($posterUrl);
        foreach ($pool as $index => $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($source !== '' && $posterIdentity->isSameAsset(ImageIdentityModel::fromUrl($source))) {
                array_splice($pool, $index, 1);

                return $image;
            }
        }

        return null;
    }

    /**
     * Body content images are bare or in a <figure>; one inside an <a> belongs
     * to another feature.
     *
     * @return list<Element>
     */
    private function reconcilableImages(Element $root): array
    {
        $images = [];
        foreach ($root->getElementsByTagName('img') as $image) {
            if ($image->closest('a') === null) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /** A player cannot sit between list items, so an item's list stands in for it. */
    private function insertAfter(Element $block, Element $player): void
    {
        $reference = $block->closest('ul, ol') ?? $block;
        $reference->parentNode?->insertBefore($player, $reference->nextSibling);
    }

    /** @param list<MediaCandidateModel> $topPlaced */
    private function prependTopPlaced(HTMLDocument $document, array $topPlaced, ?Element $belowHero): void
    {
        $root = $document->body;
        if ($root === null) {
            return;
        }

        // Insert before a fixed slot (the body's top, or right after a restored hero) so source order holds. Not
        // `?? firstChild`: a hero that is the body's last child has no nextSibling, and the players must then append.
        $reference = $belowHero !== null ? $belowHero->nextSibling : $root->firstChild;
        foreach ($topPlaced as $candidate) {
            $root->insertBefore($this->element($document, $candidate), $reference);
        }
    }

    private function element(HTMLDocument $document, MediaCandidateModel $candidate): Element
    {
        return match ($candidate->kind) {
            MediaKind::Audio => $this->player($document, 'audio', $candidate),
            MediaKind::Video => $this->player($document, 'video', $candidate),
            MediaKind::Stream => $this->player($document, 'video', $candidate),
            MediaKind::Embed => $this->markup->embedLink(
                $document,
                new EmbedTargetModel($candidate->url, $candidate->posterUrl, $candidate->label ?? 'Open the media'),
            ),
        };
    }

    private function player(HTMLDocument $document, string $tag, MediaCandidateModel $candidate): Element
    {
        $player = $document->createElement($tag);
        $player->setAttribute('controls', '');
        // Never fetch megabytes for an article the reader may only be skimming.
        $player->setAttribute('preload', 'none');
        $this->attachSource($document, $player, $candidate);
        if ($candidate->kind->isVideo() && $candidate->posterUrl !== null) {
            $player->setAttribute('poster', $candidate->posterUrl);
        }
        // The client keys its compact, translated presentation off this class, the only mark both sanitizers keep.
        if ($candidate->kind === MediaKind::Audio && $candidate->narrated) {
            $player->setAttribute('class', 'reader-narration');
        }

        return $player;
    }

    /**
     * A candidate the feed enumerated plays through a typed <source> the browser can accept or skip without a fetch;
     * one the feed never named keeps a bare src.
     */
    private function attachSource(HTMLDocument $document, Element $player, MediaCandidateModel $candidate): void
    {
        if ($candidate->mimeType === null) {
            $player->setAttribute('src', $candidate->url);

            return;
        }

        $source = $document->createElement('source');
        $source->setAttribute('src', $candidate->url);
        $source->setAttribute('type', $candidate->mimeType);
        $player->appendChild($source);
    }
}
