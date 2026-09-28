<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\RecipeFacts\RecipeFactsMarkup;
use App\Service\Reader\RecipeFacts\RecipeFactsRecognizer;

/**
 * Replaces each recognized recipe-fact block with the reader's own facts
 * figure, in place, so the surrounding article order is kept.
 */
final readonly class RecipeFactsCleaner implements BodyCleaningStepInterface
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
