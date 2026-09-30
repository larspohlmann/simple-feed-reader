<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Reader\EmptiedWrapperRemover;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use Dom\XPath;

/**
 * Removes Private Use Area code points and the elements they leave empty: the icon font that draws them is chosen by
 * a class the sanitizer strips, so each would render as a tofu box.
 */
final readonly class OrphanIconGlyphRemover implements PageRepairInterface
{
    /**
     * Private Use Area code points across the three planes — icon-font glyphs
     * with no meaning of their own once the selecting class is gone.
     */
    private const string PRIVATE_USE_PATTERN = '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u';

    public function __construct(private EmptiedWrapperRemover $wrapperRemover)
    {
    }

    public function repairIn(HTMLDocument $document): void
    {
        $emptiedHolders = [];
        foreach ($this->textNodes($document) as $node) {
            $text = $node->nodeValue;
            if ($text === null) {
                continue;
            }
            $withoutGlyphs = preg_replace(self::PRIVATE_USE_PATTERN, '', $text);
            if ($withoutGlyphs === null || $withoutGlyphs === $text) {
                continue;
            }
            $node->nodeValue = $withoutGlyphs;
            if ($node->parentNode instanceof Element) {
                $emptiedHolders[] = $node->parentNode;
            }
        }
        foreach ($emptiedHolders as $holder) {
            $this->wrapperRemover->removeIfEmptied($holder);
        }
    }

    /** @return list<Text> */
    private function textNodes(HTMLDocument $document): array
    {
        $nodes = [];
        foreach ((new XPath($document))->query('//text()') as $node) {
            if ($node instanceof Text) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
