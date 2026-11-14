<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SavedItemsModel
{
    /**
     * @param list<string> $feedUris custom feeds in savedFeedsPrefV2, each once
     * @param list<string> $listUris lists in savedFeedsPrefV2, each once
     */
    public function __construct(
        public array $feedUris = [],
        public array $listUris = [],
        public bool $hasSavedFeedsPref = false,
    ) {
    }
}
