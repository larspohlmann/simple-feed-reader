<?php

declare(strict_types=1);

namespace App\Service\Reader;

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
        if ($this->isUsable($image->getAttribute('src'))) {
            return;
        }

        $candidate = $this->candidateFor($image);
        if ($candidate === null) {
            $image->remove();

            return;
        }

        $image->setAttribute('src', $candidate);
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
        if (preg_match('/\S+/', explode(',', $srcset)[0], $matches) !== 1) {
            return null;
        }

        return $this->isUsable($matches[0]) ? $matches[0] : null;
    }
}
