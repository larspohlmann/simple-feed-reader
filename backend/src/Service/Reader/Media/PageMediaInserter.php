<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\ImageIdentity;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Places media the source page offers but the extracted body never had.
 *
 * Two phases, run around ReaderLeadImage::restore() (see ReaderBodyCleaner):
 * `plan()` is a read-only classification of each candidate as reconcilable —
 * its poster is the same asset as a body `<img>`, so the player belongs where
 * that picture already sits — or anchored — the body still holds the prose
 * block the media followed on the page, so the player goes after it — or
 * top-placed, for a candidate the body shows no trace of. `apply()` performs
 * the mutation in that order and prepends the top-placed remainder, in source
 * order. Splitting the phases lets restore() consult the plan's
 * `hasTopPlaced()` before either mutation happens.
 */
final readonly class PageMediaInserter
{
    public function __construct(private MediaMarkup $markup)
    {
    }

    public function plan(HTMLDocument $document, ArticleMedia $media): MediaInsertionPlan
    {
        $root = $document->body;
        if ($root === null || $media->isEmpty()) {
            return new MediaInsertionPlan([], [], []);
        }

        return $this->classify($media, $this->reconcilableImages($root), PageTextBlocks::fromDocument($document));
    }

    public function apply(HTMLDocument $document, MediaInsertionPlan $plan): void
    {
        foreach ($plan->reconcilePairs as $pair) {
            $pair['image']->parentNode?->replaceChild($this->element($document, $pair['candidate']), $pair['image']);
        }

        // Reversed for the same reason as prependTopPlaced: two players after
        // one block each go right behind it, so the last inserted ends up first.
        foreach (array_reverse($plan->anchoredPairs) as $pair) {
            $this->insertAfter($pair['block'], $this->element($document, $pair['candidate']));
        }

        $this->prependTopPlaced($document, $plan->topPlaced);
    }

    /** @param list<Element> $pool candidate body images, in document order */
    private function classify(ArticleMedia $media, array $pool, PageTextBlocks $bodyBlocks): MediaInsertionPlan
    {
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

        return new MediaInsertionPlan($reconciled, $anchored, $topPlaced);
    }

    /**
     * @param list<Element> $pool mutated: a claimed image is removed so a
     *                             later candidate cannot also claim it
     */
    private function claim(array &$pool, string $posterUrl): ?Element
    {
        $posterIdentity = ImageIdentity::fromUrl($posterUrl);
        foreach ($pool as $index => $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($source !== '' && $posterIdentity->isSameAsset(ImageIdentity::fromUrl($source))) {
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

    /** @param list<MediaCandidate> $topPlaced */
    private function prependTopPlaced(HTMLDocument $document, array $topPlaced): void
    {
        $root = $document->body;
        if ($root === null) {
            return;
        }

        foreach (array_reverse($topPlaced) as $candidate) {
            $root->insertBefore($this->element($document, $candidate), $root->firstChild);
        }
    }

    private function element(HTMLDocument $document, MediaCandidate $candidate): Element
    {
        return match ($candidate->kind) {
            MediaKind::Audio => $this->player($document, 'audio', $candidate),
            MediaKind::Video => $this->player($document, 'video', $candidate),
            MediaKind::Stream => $this->player($document, 'video', $candidate),
            MediaKind::Embed => $this->markup->embedLink(
                $document,
                new EmbedTarget($candidate->url, $candidate->posterUrl, $candidate->label ?? 'Open the media'),
            ),
        };
    }

    private function player(HTMLDocument $document, string $tag, MediaCandidate $candidate): Element
    {
        $player = $document->createElement($tag);
        $player->setAttribute('controls', '');
        // Never fetch megabytes for an article the reader may only be skimming.
        $player->setAttribute('preload', 'none');
        $player->setAttribute('src', $candidate->url);
        // <audio> has no poster attribute; only a video ever gets one (defect i).
        if ($candidate->kind->isVideo() && $candidate->posterUrl !== null) {
            $player->setAttribute('poster', $candidate->posterUrl);
        }

        return $player;
    }
}
