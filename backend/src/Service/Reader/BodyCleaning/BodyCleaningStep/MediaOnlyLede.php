<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Puts readability's excerpt back as the lead paragraph when only figures survived, because the page kept its lede
 * in a header block readability discarded as chrome.
 */
final readonly class MediaOnlyLede implements BodyCleaningStepInterface
{
    /**
     * A gallery article has effectively no text outside its figures; a real body
     * carries far more. This gap tells the two apart without a per-publisher rule.
     */
    private const int MIN_PROSE_LENGTH = 40;

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->restore($pass->document, $pass->input->excerpt);
    }

    private function restore(HTMLDocument $document, ?string $excerpt): void
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
