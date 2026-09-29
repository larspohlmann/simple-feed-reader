<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

use App\Service\Reader\RecipeFacts\Model\RecipeFactModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Builds the reader's fact block: a <dl> whose label/value pairs each sit in a <div>, so the client lays them out in
 * a row. `reader-recipe-facts` on the <figure> is the client marker: a class on <figure> crosses EntrySanitizer.
 */
final readonly class RecipeFactsMarkup
{
    /** @param list<RecipeFactModel> $facts */
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

    private function cell(HTMLDocument $document, RecipeFactModel $fact): Element
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
