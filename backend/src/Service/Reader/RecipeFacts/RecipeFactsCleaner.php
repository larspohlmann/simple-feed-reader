<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\BodyCleaningStep;

/**
 * Replaces each recognized recipe-fact block with the reader's own facts
 * figure, in place, so the surrounding article order is kept.
 */
final readonly class RecipeFactsCleaner implements BodyCleaningStep
{
    public function __construct(
        private RecipeFactsRecognizer $recognizer = new RecipeFactsRecognizer(),
        private RecipeFactsMarkup $markup = new RecipeFactsMarkup(),
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        foreach ($this->recognizer->recognize($pass->document) as $card) {
            $figure = $this->markup->figureFor($pass->document, $card->facts);
            $card->container->parentNode?->replaceChild($figure, $card->container);
        }
    }
}
