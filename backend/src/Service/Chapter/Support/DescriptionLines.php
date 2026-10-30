<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Service\Chapter\Pass\DescriptionLine;
use Dom\Document;
use Dom\Element;

/** A description's non-blank `<br>`-separated lines in reading order; a block element ends a line and holds lines of its own. */
final class DescriptionLines
{
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'ol', 'p', 'pre', 'section', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /** @return list<DescriptionLine> */
    public static function of(Document $document): array
    {
        if ($document->body === null) {
            return [];
        }

        return array_values(array_filter(
            self::inBlock($document->body),
            static fn (DescriptionLine $line): bool => trim($line->text()) !== '',
        ));
    }

    /** @return list<DescriptionLine> */
    private static function inBlock(Element $block): array
    {
        $lines = [];
        $nodes = [];
        foreach ($block->childNodes as $child) {
            if ($child instanceof Element && self::isBlock($child)) {
                $lines[] = new DescriptionLine($block, $nodes, null);
                array_push($lines, ...self::inBlock($child));
                $nodes = [];
                continue;
            }
            if ($child instanceof Element && self::isBreak($child)) {
                $lines[] = new DescriptionLine($block, $nodes, $child);
                $nodes = [];
                continue;
            }
            $nodes[] = $child;
        }
        $lines[] = new DescriptionLine($block, $nodes, null);

        return $lines;
    }

    private static function isBlock(Element $element): bool
    {
        return \in_array($element->localName, self::BLOCKS, true);
    }

    private static function isBreak(Element $element): bool
    {
        return $element->localName === 'br';
    }

    private function __construct()
    {
    }
}
