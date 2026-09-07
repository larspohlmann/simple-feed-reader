<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Builds the reader's own fact block: a <dl> of label/value pairs, each pair
 * wrapped in a <div> so the client can lay the pairs out as a row of cells.
 * class="reader-recipe-facts" on the <figure> is the client marker; it is the
 * one attribute channel that crosses EntrySanitizer (a class on <figure>).
 */
final readonly class RecipeFactsMarkup
{
    /** @param list<RecipeFact> $facts */
    public function figureFor(HTMLDocument $document, array $facts): Element
    {
        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'reader-recipe-facts');

        $list = $document->createElement('dl');
        foreach ($facts as $fact) {
            $list->appendChild($this->cell($document, $fact));
        }
        $figure->appendChild($list);

        return $figure;
    }

    private function cell(HTMLDocument $document, RecipeFact $fact): Element
    {
        $cell = $document->createElement('div');
        $cell->appendChild($this->term($document, 'dt', $fact->label));
        $cell->appendChild($this->term($document, 'dd', $fact->value));

        return $cell;
    }

    private function term(HTMLDocument $document, string $tag, string $text): Element
    {
        $element = $document->createElement($tag);
        $element->appendChild($document->createTextNode($text));

        return $element;
    }
}
