<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Scraper\Support\TextNormalizer;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * A card's title: its first h1 to h4, else its first title- or headline-classed element, else the anchor's first text
 * node, never the anchor's full text, which on a heading-less card mashes title, byline and teaser together.
 */
final readonly class CardTitle
{
    public function of(Element $container, Element $anchor): ?string
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

    /** The first match in document order wins; a later sibling match (card__subtitle) must never override it. */
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
     * Depth-first, because textContent gives block elements no newline: on minified HTML, title, byline and teaser
     * run together. Iterative, because a recursive walk over adversarially deep nesting exhausts the stack with an
     * \Error that escapes the FeedParseException channel.
     */
    private static function firstAnchorText(Element $anchor): ?string
    {
        /** @var list<Node> $stack */
        $stack = [$anchor];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof Text) {
                $text = TextNormalizer::normalize($node->data);
                if ($text !== '') {
                    return $text;
                }
                continue;
            }
            if (!$node instanceof Element) {
                continue;
            }
            // Pushed in reverse, so the leftmost child pops first.
            for ($child = $node->lastChild; $child !== null; $child = $child->previousSibling) {
                $stack[] = $child;
            }
        }

        return null;
    }
}
