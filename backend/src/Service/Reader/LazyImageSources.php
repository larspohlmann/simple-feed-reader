<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\ImageRendition;
use App\Service\Html\ImageSourceUrl;
use App\Service\Html\PictureSources;
use App\Service\Html\Srcset;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Restores the real source of a lazy-loaded <img> before readability sees it.
 *
 * Lazy-loading sites ship a blank `data:` placeholder in `src` and keep the
 * true URL in a `data-*` attribute (#467: WP Rocket's `data-lazy-src`).
 * Neither survives EntrySanitizer — the placeholder is a forbidden scheme,
 * the data attribute isn't on the allow-list — so the reader rendered an
 * empty frame. Promoting the candidate here lets the sanitizer see an
 * ordinary image and keep its scheme guard intact.
 *
 * A responsive <picture> hides its URL the same way without being lazy: the
 * <img> carries no `src`, and candidates sit on sibling <source srcset>
 * elements (#498: ZDFheute). A lazy <picture> keeps those on `data-srcset`
 * (nature.com, #789), read by the same lazy attributes as the <img>. The last
 * resort looks one level out, into the picture the image belongs to.
 *
 * An image with no usable candidate is removed: an unloadable <img> is a
 * broken frame, and leaving it fools HeroImageSelector into thinking the body
 * already shows a picture, suppressing the hero.
 *
 * Once an image inside a <picture> owns a usable src, the picture is
 * flattened to that image so the sibling <source> set cannot override it.
 * NDR lists a 20w placeholder first with `sizes="1px"`; its script resizes
 * after layout, but the reader strips the script, so a surviving <source>
 * would leave the browser on the placeholder (entry 480204).
 */
final readonly class LazyImageSources
{
    /** Attributes holding a single URL, in the order publishers prefer them. */
    private const array URL_ATTRIBUTES = ['data-lazy-src', 'data-src', 'data-original'];

    /** Attributes holding a candidate list; the first entry is taken. */
    private const array SRCSET_ATTRIBUTES = ['data-lazy-srcset', 'data-srcset', 'srcset'];

    public function __construct(private PictureSources $pictureSources)
    {
    }

    public function resolveIn(HTMLDocument $document): void
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
     * A <picture>'s <source> set carries the real renditions; its <img> is
     * only the fallback for clients without <picture> support, and
     * publishers often make that fallback a tiny LQIP placeholder (taz ships
     * a 14px webp, entry 486683). Adopt the widest <source> — unless the
     * <img>'s own src is already at least as wide, the mirror case where the
     * placeholder hides in a <source> and the real photo is the <img> (NDR,
     * entry 480204). A <source> scoped by `media` to a narrower viewport is a
     * mobile crop, not a desktop rendition, so it's no candidate at all (zeit
     * lists those first and measures nothing, entry 497686).
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
     * A lazy-loaded <img> outside any <picture> can pin its own src to a tiny
     * LQIP rendition and keep the real sizes in its srcset (heise ships the lead
     * image so, inside a <noscript> the sanitizer would drop; entry 508092).
     * EntrySanitizer strips srcset, so the widest rendition has to move into src
     * here or the reader shows the placeholder. Unlike a <picture> fallback,
     * a bare <img>'s src is the author's chosen rendition, so only a src that
     * measurably undersizes the srcset is upgraded; an unmeasured one stays.
     */
    private function preferWiderOwnSrcset(Element $image): void
    {
        if (
            $this->enclosingPicture($image) !== null
            || ImageRendition::widthFromUrl($image->getAttribute('src')) === null
        ) {
            return;
        }

        $widest = Srcset::widest($image->getAttribute('srcset'));
        if ($widest === null || !ImageSourceUrl::isUsable($widest->url)) {
            return;
        }

        $this->adoptWiderRendition($image, ImageRendition::measuredFromUrl($widest->url, $widest->width));
    }

    /**
     * Moves a wider rendition into the <img>'s src, dropping the width and
     * height the smaller rendition carried. The src stays when it already
     * measures at least as wide, so a real photo is never traded for a
     * narrower one (the NDR mirror case, entry 480204).
     */
    private function adoptWiderRendition(Element $image, ImageRendition $candidate): void
    {
        $imageSource = $image->getAttribute('src');
        if ($imageSource === $candidate->url) {
            return;
        }

        $imageWidth = ImageRendition::widthFromUrl($imageSource);
        if ($imageWidth !== null && ($candidate->width === null || $imageWidth >= $candidate->width)) {
            return;
        }

        $image->setAttribute('src', $candidate->url);
        $image->removeAttribute('width');
        $image->removeAttribute('height');
    }

    /**
     * Replaces the enclosing <picture> with the image, dropping the sibling
     * <source> elements. The image now carries an authoritative src, and a
     * surviving <source> would override it: NDR lists a 20w placeholder with
     * `sizes="1px"`, so once its resize script is stripped the browser picks
     * the placeholder over the real photo (entry 480204). The reader shows one
     * picture at a single column width, so the <source> set's responsive
     * candidates have no use here.
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
     * A responsive <picture> may leave its <img> bare and carry the URL on a
     * sibling <source srcset> (ZDFheute is the case, #498). The <img> is the
     * element the browser renders, so it has to survive with a source of its
     * own — removing it drops the picture and the figure built around it.
     */
    private function candidateFromEnclosingPicture(Element $image): ?string
    {
        $picture = $this->enclosingPicture($image);

        return $picture === null ? null : $this->pictureSources->firstUsableUrl($picture);
    }

    /**
     * The <picture> an image belongs to. The HTML5 parser treats <source> as a
     * void element, so the candidates and the <img> stay siblings under the
     * <picture> however the page spells its source tags — the image is the
     * picture's direct child.
     */
    private function enclosingPicture(Element $image): ?Element
    {
        $parent = $image->parentNode;

        return $parent instanceof Element && $parent->localName === 'picture' ? $parent : null;
    }

    /** The first candidate of a srcset list, or null when it yields nothing usable. */
    private function usableSrcsetHead(string $srcset): ?string
    {
        $candidate = Srcset::firstUrl($srcset);

        return $candidate !== null && ImageSourceUrl::isUsable($candidate) ? $candidate : null;
    }
}
