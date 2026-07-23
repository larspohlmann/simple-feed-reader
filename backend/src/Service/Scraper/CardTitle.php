<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use Dom\Element;
use Dom\Text;

/**
 * Title heuristic for one card container + anchor (split out of CardFields
 * to keep each class' complexity readable). Candidate order per the scraper
 * design spec:
 * - first heading (h1-h4) in the container;
 * - else the FIRST element whose class matches title|headline, descending to
 *   its deepest matching descendant (card__title > card__title-text) — a
 *   later sibling match (card__subtitle) must never override the title;
 * - else the anchor's first text node — never the anchor's full text, which
 *   on heading-less cards would mash title, byline, and description together.
 * Length rules (min 5, truncate 300) are applied by the caller, CardFields.
 */
final class CardTitle
{
    public static function of(Element $container, Element $anchor): ?string
    {
        return self::headingTitle($container)
            ?? self::classHintedTitle($container)
            ?? self::firstAnchorText($anchor);
    }

    private static function headingTitle(Element $container): ?string
    {
        $heading = $container->querySelector('h1, h2, h3, h4');
        if (!$heading instanceof Element) {
            return null;
        }
        $text = TextNormalizer::normalize($heading->textContent ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * First matching element in document order wins, then descends into it:
     * a deeper matching descendant (card__title > card__title-text) is the
     * more precise text node, but a later SIBLING match must not override —
     * "last match wins" made card__subtitle beat the card__title before it.
     */
    private static function classHintedTitle(Element $container): ?string
    {
        foreach ($container->querySelectorAll('*') as $element) {
            if (!self::isTitleClassed($element)) {
                continue;
            }
            $text = TextNormalizer::normalize($element->textContent ?? '');
            if ($text !== '') {
                return self::deepestTitleText($element) ?? $text;
            }
        }

        return null;
    }

    /** True when the element's class names it a title or headline. */
    private static function isTitleClassed(Element $element): bool
    {
        $class = $element->getAttribute('class');

        return $class !== null && preg_match('/(title|headline)/i', $class) === 1;
    }

    /**
     * Text of the deepest title-classed descendant below the first match —
     * nested wrappers put the cleanest text innermost. Equal depths go to the
     * earlier node in document order; empty descendants are ignored.
     */
    private static function deepestTitleText(Element $first): ?string
    {
        $best = null;
        $bestDepth = 0;
        foreach ($first->querySelectorAll('*') as $element) {
            if (!self::isTitleClassed($element)) {
                continue;
            }
            $text = TextNormalizer::normalize($element->textContent ?? '');
            if ($text === '') {
                continue;
            }
            $depth = self::depthBelow($element, $first);
            if ($depth > $bestDepth) {
                $best = $text;
                $bestDepth = $depth;
            }
        }

        return $best;
    }

    /** Element depth below an ancestor: 1 for a direct child, 2 for a grandchild, … */
    private static function depthBelow(Element $element, Element $ancestor): int
    {
        $depth = 1;
        $parent = $element->parentElement;
        while ($parent !== null && $parent !== $ancestor) {
            $depth++;
            $parent = $parent->parentElement;
        }

        return $depth;
    }

    /**
     * First non-empty text node under the anchor, depth-first in document
     * order. Splitting textContent on newlines breaks on minified HTML —
     * block elements contribute no newline to textContent, so title, byline,
     * and teaser mash into one "line". The DOM keeps them as separate text
     * nodes regardless of source formatting.
     */
    private static function firstAnchorText(Element $anchor): ?string
    {
        foreach ($anchor->childNodes as $child) {
            if ($child instanceof Text) {
                $text = TextNormalizer::normalize($child->data);
            } elseif ($child instanceof Element) {
                $text = self::firstAnchorText($child) ?? '';
            } else {
                continue;
            }
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
