<?php

declare(strict_types=1);

namespace App\Service\Reader\Repair;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use Dom\XPath;

/**
 * Icon-font glyphs sit in a Private Use Area code point selected by a CSS class.
 * The sanitizer strips the class, so the glyph loses its font and the browser
 * paints a tofu box (taz's pull-quote mark, U+E80F). The dead code points are
 * removed here, with the now-empty element that held them.
 */
final readonly class OrphanIconGlyphRemover implements PageRepair
{
    /**
     * Private Use Area code points across the three planes — icon-font glyphs
     * with no meaning of their own once the selecting class is gone.
     */
    private const string PRIVATE_USE_PATTERN = '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u';

    /** Elements that carry content without text, so an empty one still counts. */
    private const array EMBEDDED_TAGS = [
        'img', 'picture', 'source', 'svg', 'video', 'audio', 'iframe', 'br', 'hr', 'input',
    ];

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
            $this->pruneWhileEmpty($holder);
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

    /**
     * Drop an element the glyph strip left empty, then walk up dropping each
     * ancestor the removal in turn empties — a pull-quote's icon <span> and the
     * <p> that held nothing else both go.
     */
    private function pruneWhileEmpty(Element $element): void
    {
        while (
            $element->parentNode !== null
            && trim((string) $element->textContent) === ''
            && !$this->holdsEmbeddedContent($element)
        ) {
            $parent = $element->parentNode;
            $parent->removeChild($element);
            if (!$parent instanceof Element) {
                return;
            }
            $element = $parent;
        }
    }

    private function holdsEmbeddedContent(Element $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array($descendant->localName, self::EMBEDDED_TAGS, true)) {
                return true;
            }
        }

        return false;
    }
}
