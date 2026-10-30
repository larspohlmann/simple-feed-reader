<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Service\Chapter\Pass\DescriptionLine;
use Dom\Document;
use Dom\Element;

final class DescriptionLines
{
    private const string BLOCK_SELECTOR = 'address,article,aside,blockquote,dd,div,dl,dt,figcaption,figure,footer,'
        . 'h1,h2,h3,h4,h5,h6,header,hr,li,ol,p,pre,section,table,tbody,td,tfoot,th,thead,tr,ul';

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
            if (!$child instanceof Element) {
                $nodes[] = $child;
                continue;
            }
            if (self::isBlock($child)) {
                $lines[] = new DescriptionLine($block, $nodes, null);
                array_push($lines, ...self::inBlock($child));
                $nodes = [];
            } elseif (self::isBreak($child)) {
                $lines[] = new DescriptionLine($block, $nodes, $child);
                $nodes = [];
            } else {
                $nodes[] = $child;
            }
        }
        $lines[] = new DescriptionLine($block, $nodes, null);

        return $lines;
    }

    private static function isBlock(Element $element): bool
    {
        return $element->matches(self::BLOCK_SELECTOR) || $element->querySelector(self::BLOCK_SELECTOR) !== null;
    }

    private static function isBreak(Element $element): bool
    {
        return $element->localName === 'br';
    }

    private function __construct()
    {
    }
}
