<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\ImageRendition;
use App\Service\Html\Srcset;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Restores the real source of a lazy-loaded <img> before readability sees it.
 *
 * Lazy-loading sites ship a blank `data:` placeholder in `src` and keep the
 * true URL in a `data-*` attribute (#467: WP Rocket's `data-lazy-src`). Neither
 * survives EntrySanitizer — the placeholder is a forbidden scheme, the data
 * attribute is not on the allow-list — so the reader rendered an empty frame.
 * Promoting the candidate here means the sanitizer sees an ordinary image and
 * keeps its scheme guard intact.
 *
 * A responsive <picture> hides its URL the same way without being lazy at all:
 * the <img> carries no `src` and the candidates sit on sibling <source srcset>
 * elements (#498: ZDFheute). The last resort therefore looks one level out,
 * into the picture the image belongs to.
 *
 * An image with no usable candidate is removed: an <img> the client cannot load
 * is a broken frame, and leaving it also fools HeroImageSelector into thinking
 * the body already shows a picture, suppressing the hero.
 *
 * Once an image inside a <picture> owns a usable src, the picture is flattened
 * to that image so the sibling <source> set cannot override the choice. NDR
 * lists a 20w placeholder first and sets `sizes="1px"`; its own script rewrites
 * the size after layout, but the reader strips the script, so a surviving
 * <source> would leave the browser on the placeholder (entry 480204).
 */
final readonly class LazyImageSources
{
    /** Attributes holding a single URL, in the order publishers prefer them. */
    private const array URL_ATTRIBUTES = ['data-lazy-src', 'data-src', 'data-original'];

    /** Attributes holding a candidate list; the first entry is taken. */
    private const array SRCSET_ATTRIBUTES = ['data-lazy-srcset', 'data-srcset', 'srcset'];

    /** A URL carrying a scheme that is neither http nor https — never promoted. */
    private const string FOREIGN_SCHEME = '#^(?!https?://)[a-z][a-z0-9+.\-]*:#i';

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
        if ($this->isUsable($image->getAttribute('src'))) {
            $this->preferWiderPictureSource($image);

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
     * A <picture>'s <source> set carries the real renditions; its <img> is only
     * the fallback for a client without <picture> support, and publishers
     * routinely make that fallback a tiny LQIP placeholder (taz ships a 14px
     * webp, entry 486683). So adopt the widest <source> — unless the <img>'s own
     * src is already at least as wide, the mirror case where the placeholder
     * hides in a <source> and the real photo is the <img> (NDR, entry 480204).
     */
    private function preferWiderPictureSource(Element $image): void
    {
        $picture = $this->enclosingPicture($image);
        if ($picture === null) {
            return;
        }

        $widest = $this->widestPictureSource($picture);
        if ($widest === null) {
            return;
        }

        $imageSource = $image->getAttribute('src');
        if ($imageSource === $widest->url) {
            return;
        }

        $imageWidth = $this->declaredWidth($imageSource);
        if ($imageWidth !== null && ($widest->width === null || $imageWidth >= $widest->width)) {
            return;
        }

        $image->setAttribute('src', $widest->url);
        $image->removeAttribute('width');
        $image->removeAttribute('height');
    }

    /**
     * The widest usable rendition the picture's <source> set offers. When no
     * source declares a width the first usable one stands in, so a src-less
     * picture still resolves to a real image.
     */
    private function widestPictureSource(Element $picture): ?ImageRendition
    {
        $widest = null;
        foreach ($picture->getElementsByTagName('source') as $source) {
            $rendition = $this->renditionOf($source);
            if ($rendition === null) {
                continue;
            }
            if ($widest === null || $this->outmeasures($rendition, $widest)) {
                $widest = $rendition;
            }
        }

        return $widest;
    }

    /** A source's widest usable candidate, its width filled from the URL when the
     *  srcset omits a descriptor. Null when the source carries nothing loadable. */
    private function renditionOf(Element $source): ?ImageRendition
    {
        $candidate = Srcset::widest($source->getAttribute('srcset'));
        if ($candidate === null || !$this->isUsable($candidate->url)) {
            return null;
        }

        return new ImageRendition($candidate->url, $candidate->width ?? $this->declaredWidth($candidate->url));
    }

    /** True when a rendition outsizes the incumbent; an unmeasured one never does. */
    private function outmeasures(ImageRendition $candidate, ImageRendition $incumbent): bool
    {
        if ($candidate->width === null) {
            return false;
        }

        return $incumbent->width === null || $candidate->width > $incumbent->width;
    }

    /** The pixel width a URL states in a `width=` or `w=` query, or null. */
    private function declaredWidth(?string $url): ?int
    {
        if ($url === null || preg_match('/[?&](?:width|w)=(\d+)/', $url, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Replaces the enclosing <picture> with the image, dropping the sibling
     * <source> elements. The image now carries an authoritative src, and a
     * surviving <source> would override it: NDR lists a 20w placeholder first and
     * sets `sizes="1px"`, so once its own resize script is stripped the browser
     * picks the placeholder over the real photo (entry 480204). The reader shows
     * one picture at a single column width, so the responsive candidates that the
     * <source> set carried have no use here.
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
            if ($this->isUsable($candidate)) {
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
        if ($picture === null) {
            return null;
        }

        foreach ($picture->getElementsByTagName('source') as $source) {
            $candidate = $this->usableSrcsetHead($source->getAttribute('srcset') ?? '');
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A relative URL stays a candidate: readability resolves it against the
     * page's final URL right after this step.
     */
    private function isUsable(?string $url): bool
    {
        return $url !== null && $url !== '' && preg_match(self::FOREIGN_SCHEME, $url) !== 1;
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

        return $candidate !== null && $this->isUsable($candidate) ? $candidate : null;
    }
}
