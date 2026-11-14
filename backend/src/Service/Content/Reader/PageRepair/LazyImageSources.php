<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Html\Model\ImageRenditionModel;
use App\Service\Html\PictureSources;
use App\Service\Html\Support\ImageSourceUrl;
use App\Service\Html\Support\Srcset;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Gives every <img> a usable src from its lazy `data-*` attributes, srcset or <picture> sources, since EntrySanitizer
 * keeps neither a `data:` placeholder nor those attributes. An image with no candidate is removed (a broken frame
 * would also suppress the hero), and a <picture> around a resolved image is flattened to it.
 */
final readonly class LazyImageSources implements PageRepairInterface
{
    /** Attributes holding a single URL, in the order publishers prefer them. */
    private const array URL_ATTRIBUTES = ['data-lazy-src', 'data-src', 'data-original'];

    /** Attributes holding a candidate list; the first entry is taken. */
    private const array SRCSET_ATTRIBUTES = ['data-lazy-srcset', 'data-srcset', 'srcset'];

    public function __construct(private PictureSources $pictureSources)
    {
    }

    public function repairIn(HTMLDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
            $this->resolveImage($image);
        }
    }

    private function resolveImage(Element $image): void
    {
        if (!$this->ensureUsableSource($image)) {
            $image->remove();

            return;
        }

        $this->flattenEnclosingPicture($image);
    }

    /**
     * Guarantees the image carries a usable src, promoting a lazy candidate when
     * its own src is a placeholder. False when no candidate exists at all.
     */
    private function ensureUsableSource(Element $image): bool
    {
        if (ImageSourceUrl::isUsable($image->getAttribute('src'))) {
            $this->preferWiderPictureSource($image);
            $this->preferWiderOwnSrcset($image);

            return true;
        }

        $candidate = $this->candidateFor($image);
        if ($candidate === null) {
            return false;
        }

        $image->setAttribute('src', $candidate);

        return true;
    }

    /**
     * Adopts the widest <source>, since the <img> is only the fallback and often a tiny placeholder. An <img> that
     * already measures as wide keeps its src: then the placeholder is the <source>.
     */
    private function preferWiderPictureSource(Element $image): void
    {
        $picture = $this->enclosingPicture($image);
        if ($picture === null) {
            return;
        }

        $widest = $this->pictureSources->widest($picture);
        if ($widest !== null) {
            $this->adoptWiderRendition($image, $widest);
        }
    }

    /**
     * Moves a bare <img>'s widest srcset rendition into src: EntrySanitizer strips srcset, and a lazy image may pin src
     * to a placeholder. Only a src that measures narrower is replaced; an unmeasured src is the author's choice.
     */
    private function preferWiderOwnSrcset(Element $image): void
    {
        if (
            $this->enclosingPicture($image) !== null
            || ImageRenditionModel::widthFromUrl($image->getAttribute('src')) === null
        ) {
            return;
        }

        $widest = Srcset::widest($image->getAttribute('srcset'));
        if ($widest === null || !ImageSourceUrl::isUsable($widest->url)) {
            return;
        }

        $this->adoptWiderRendition($image, ImageRenditionModel::measuredFromUrl($widest->url, $widest->width));
    }

    /**
     * Moves a wider rendition into src and drops the width and height the smaller one carried. A measured src stays
     * unless the candidate measures wider, so a real photo is never traded for a narrower one.
     */
    private function adoptWiderRendition(Element $image, ImageRenditionModel $candidate): void
    {
        $imageSource = $image->getAttribute('src');
        if ($imageSource === $candidate->url) {
            return;
        }

        $imageWidth = ImageRenditionModel::widthFromUrl($imageSource);
        if ($imageWidth !== null && ($candidate->width === null || $imageWidth >= $candidate->width)) {
            return;
        }

        $image->setAttribute('src', $candidate->url);
        $image->removeAttribute('width');
        $image->removeAttribute('height');
    }

    /**
     * Replaces the enclosing <picture> with the image: a surviving <source> would override the resolved src, and
     * with the page's resize script stripped the browser may pick a placeholder rendition from it.
     */
    private function flattenEnclosingPicture(Element $image): void
    {
        $picture = $this->enclosingPicture($image);
        if ($picture === null || $picture->parentNode === null) {
            return;
        }

        $picture->parentNode->replaceChild($image, $picture);
    }

    private function candidateFor(Element $image): ?string
    {
        foreach (self::URL_ATTRIBUTES as $attribute) {
            $candidate = trim($image->getAttribute($attribute) ?? '');
            if (ImageSourceUrl::isUsable($candidate)) {
                return $candidate;
            }
        }

        foreach (self::SRCSET_ATTRIBUTES as $attribute) {
            $candidate = $this->usableSrcsetHead($image->getAttribute($attribute) ?? '');
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $this->candidateFromEnclosingPicture($image);
    }

    /**
     * A src-less <img> in a <picture> takes its first usable <source> URL: removing the <img> the browser renders
     * would drop the picture and the figure built around it (#498).
     */
    private function candidateFromEnclosingPicture(Element $image): ?string
    {
        $picture = $this->enclosingPicture($image);

        return $picture === null ? null : $this->pictureSources->firstUsableUrl($picture);
    }

    /**
     * The parent <picture>. The HTML5 parser treats <source> as void, so the <img> stays a direct child of its
     * picture however the page spells its source tags.
     */
    private function enclosingPicture(Element $image): ?Element
    {
        $parent = $image->parentNode;

        return $parent instanceof Element && $parent->localName === 'picture' ? $parent : null;
    }

    private function usableSrcsetHead(string $srcset): ?string
    {
        $candidate = Srcset::firstUrl($srcset);

        return $candidate !== null && ImageSourceUrl::isUsable($candidate) ? $candidate : null;
    }
}
