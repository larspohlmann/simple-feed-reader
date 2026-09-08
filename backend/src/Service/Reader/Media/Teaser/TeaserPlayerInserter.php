<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Teaser;

use App\Service\Reader\ImageIdentity;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Puts an inline teaser back where the extraction left its bare thumbnail: the
 * body <img> whose asset is the teaser's still becomes the reconstructed player.
 *
 * A teaser the media pipeline already inserted (its file is among the article's
 * media) is skipped, so the article's own narration or lead video is never
 * mistaken for a teaser and never overwrites its hero. Each teaser claims one
 * thumbnail, and an unmatched teaser is dropped rather than forced into the body.
 */
final readonly class TeaserPlayerInserter
{
    public function __construct(private TeaserPlayerMarkup $markup)
    {
    }

    /**
     * @param list<TeaserPlayer> $teasers
     * @param list<string>       $articleMediaUrls the players the media pipeline already placed
     */
    public function insert(HTMLDocument $document, array $teasers, array $articleMediaUrls): void
    {
        $root = $document->body;
        $pending = $this->notAlreadyPlaced($teasers, $articleMediaUrls);
        if ($root === null || $pending === []) {
            return;
        }

        // Each still's identity is derived once, not per body image it is tried against.
        $stills = array_map(
            static fn (TeaserPlayer $teaser): ImageIdentity => ImageIdentity::fromUrl($teaser->posterUrl),
            $pending,
        );
        foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
            $index = $this->matching($stills, $image);
            if ($index !== null) {
                $this->replace($document, $image, $pending[$index]);
                unset($stills[$index]);
            }
        }
    }

    /**
     * @param list<TeaserPlayer> $teasers
     * @param list<string>       $articleMediaUrls
     *
     * @return array<int, TeaserPlayer>
     */
    private function notAlreadyPlaced(array $teasers, array $articleMediaUrls): array
    {
        $placed = array_fill_keys($articleMediaUrls, true);

        return array_filter($teasers, static fn (TeaserPlayer $teaser): bool => !isset($placed[$teaser->mediaUrl]));
    }

    /**
     * @param array<int, ImageIdentity> $stills the pending teasers' still identities, keyed as $pending
     */
    private function matching(array $stills, Element $image): ?int
    {
        $source = $image->getAttribute('src') ?? '';
        if ($source === '') {
            return null;
        }
        $imageAsset = ImageIdentity::fromUrl($source);

        return array_find_key($stills, static fn (ImageIdentity $still): bool => $imageAsset->isSameAsset($still));
    }

    private function replace(HTMLDocument $document, Element $image, TeaserPlayer $teaser): void
    {
        $target = $this->orphanWrapper($image) ?? $image;
        $target->parentNode?->replaceChild($this->markup->figureFor($document, $teaser), $target);
    }

    /** The <p> a thumbnail sits alone in goes with it, so no empty paragraph is left behind. */
    private function orphanWrapper(Element $image): ?Element
    {
        $parent = $image->parentNode;

        return $parent instanceof Element
            && $parent->localName === 'p'
            && trim((string) $parent->textContent) === ''
                ? $parent
                : null;
    }
}
