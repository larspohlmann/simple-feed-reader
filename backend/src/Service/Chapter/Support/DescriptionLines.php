<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Service\Chapter\Pass\DescriptionLine;
use Dom\Document;
use Dom\Element;
use Dom\Node;

final class DescriptionLines
{
    /** @return list<DescriptionLine> */
    public static function of(Document $document): array
    {
        $lines = [];
        foreach ($document->querySelectorAll('p, li') as $block) {
            array_push($lines, ...self::inBlock($block));
        }

        return $lines;
    }

    /** @return list<DescriptionLine> */
    private static function inBlock(Element $block): array
    {
        $lines = [];
        $nodes = [];
        foreach (iterator_to_array($block->childNodes, false) as $child) {
            if (self::isBreak($child)) {
                $lines[] = new DescriptionLine($block, $nodes, $child);
                $nodes = [];
                continue;
            }
            $nodes[] = $child;
        }
        $lines[] = new DescriptionLine($block, $nodes, null);

        return $lines;
    }

    /** @phpstan-assert-if-true Element $node */
    private static function isBreak(Node $node): bool
    {
        return $node instanceof Element && $node->localName === 'br';
    }

    private function __construct()
    {
    }
}
