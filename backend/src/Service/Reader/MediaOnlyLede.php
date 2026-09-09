<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Some articles keep their whole lede in a header block that readability discards
 * as chrome, leaving a body that is only media — a photo gallery, a lone video.
 * When nothing but figures survives, restore readability's excerpt as a lead
 * paragraph so the reader shows the words the page led with, not just the images.
 */
final readonly class MediaOnlyLede
{
    /**
     * A gallery article has effectively no text outside its figures; a real body
     * carries far more. This gap tells the two apart without a per-publisher rule.
     */
    private const int MIN_PROSE_LENGTH = 40;

    public function restore(HTMLDocument $document, ?string $excerpt): void
    {
        $lede = trim($excerpt ?? '');
        $body = $document->getElementsByTagName('body')->item(0);
        if ($lede === '' || !$body instanceof Element || $this->hasProse($body)) {
            return;
        }

        $paragraph = $document->createElement('p');
        $paragraph->appendChild($document->createTextNode($lede));
        $body->insertBefore($paragraph, $body->firstChild);
    }

    private function hasProse(Element $body): bool
    {
        return mb_strlen(trim($this->textOutsideFigures($body))) >= self::MIN_PROSE_LENGTH;
    }

    /** Figure text is a caption or credit, not article body prose, so it never counts. */
    private function textOutsideFigures(Node $node): string
    {
        $text = '';
        for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
            if ($child instanceof Text) {
                $text .= $child->textContent;
            } elseif ($child instanceof Element && strtoupper($child->nodeName) !== 'FIGURE') {
                $text .= $this->textOutsideFigures($child);
            }
        }

        return $text;
    }
}
