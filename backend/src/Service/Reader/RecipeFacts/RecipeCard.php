<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

use Dom\Element;

/**
 * A recognized recipe-fact block: the publisher's container to remove, and the
 * facts recovered from it to render in its place.
 */
final readonly class RecipeCard
{
    /** @param list<RecipeFact> $facts */
    public function __construct(
        public Element $container,
        public array $facts,
    ) {
    }
}
