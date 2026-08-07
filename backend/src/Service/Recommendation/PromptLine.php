<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One entry as it appears in a recommendation prompt: a history line carries
 * `entryId: null` so the model has nothing to pick from it, while a candidate
 * line's non-null id is what a recommendation later resolves back to an Entry.
 */
final readonly class PromptLine
{
    public function __construct(
        public ?int $entryId,
        public string $title,
        public string $feedName,
        public string $date,
        public ?string $description,
    ) {
    }
}
