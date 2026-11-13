<?php

declare(strict_types=1);

namespace App\Service\Tag\Model;

/** Which surfaces a tag lets its feeds into; a null switch stays as it is. */
final readonly class TagInclusionChangeModel
{
    public function __construct(
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
        public ?bool $includeInSavedSearches = null,
        public ?bool $includeInTrending = null,
    ) {
    }
}
