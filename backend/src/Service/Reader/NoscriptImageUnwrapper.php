<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Promotes the real photo lazy-loading sites hide inside <noscript> (heise
 * ships it there, next to a `data:` placeholder <img>). The HTML5 parser keeps
 * <noscript> content as ordinary DOM with scripting disabled, but the
 * sanitizer drops the whole tag with its content, so the image never reaches
 * the client unless it is promoted out first (#894). A <noscript> with no
 * image is a no-JS text fallback and is left alone.
 */
final readonly class NoscriptImageUnwrapper
{
    public function unwrapIn(HTMLDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('noscript')) as $noscript) {
            $this->promoteImageOutOf($noscript);
        }
    }

    private function promoteImageOutOf(Element $noscript): void
    {
        if ($noscript->getElementsByTagName('img')->length === 0) {
            return;
        }

        $this->removePrecedingPlaceholder($noscript);
        $noscript->replaceWith(...iterator_to_array($noscript->childNodes));
    }

    private function removePrecedingPlaceholder(Element $noscript): void
    {
        $sibling = $noscript->previousElementSibling;
        if ($sibling !== null && $this->isPlaceholderImage($sibling)) {
            $sibling->remove();
        }
    }

    /** An <img>, or a wrapper around exactly one and no text of its own. */
    private function isPlaceholderImage(Element $element): bool
    {
        if ($element->localName === 'img') {
            return true;
        }

        return $element->getElementsByTagName('img')->length === 1
            && trim((string) $element->textContent) === '';
    }
}
