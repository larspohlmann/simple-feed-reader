<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/** One entry in a prompt; only candidate lines print their id, so a history line gives the model nothing to pick. */
final readonly class PromptLine
{
    public function __construct(
        public int $entryId,
        public string $title,
        public string $feedName,
        public string $date,
        public ?string $description,
    ) {
    }
}
