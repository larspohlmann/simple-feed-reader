<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi\Model;

/** What the story settings ask to store; the connection is still an id the editor resolves for the account. */
final readonly class StorySettingsChangeModel
{
    public function __construct(
        public ?int $writingConnectionId,
        public bool $titlesEnabled,
    ) {
    }
}
