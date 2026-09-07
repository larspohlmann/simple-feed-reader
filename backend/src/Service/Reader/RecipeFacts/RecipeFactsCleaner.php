<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

use Dom\HTMLDocument;

/**
 * Replaces each recognized recipe-fact block with the reader's own facts
 * figure, in place, so the surrounding article order is kept.
 */
final readonly class RecipeFactsCleaner
{
    public function __construct(
        private RecipeFactsRecognizer $recognizer = new RecipeFactsRecognizer(),
        private RecipeFactsMarkup $markup = new RecipeFactsMarkup(),
    ) {
    }

    public function cleanIn(HTMLDocument $document): void
    {
        foreach ($this->recognizer->recognize($document) as $card) {
            $figure = $this->markup->figureFor($document, $card->facts);
            $card->container->parentNode?->replaceChild($figure, $card->container);
        }
    }
}
