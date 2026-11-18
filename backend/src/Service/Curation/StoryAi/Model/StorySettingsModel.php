<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi\Model;

use App\Entity\AiProviderSettings;

/** Everything the story settings show: the switches, the stored pick, the resolved writer and who could write. */
final readonly class StorySettingsModel
{
    /** @param list<AiProviderSettings> $writingCandidates */
    public function __construct(
        public bool $titlesEnabled,
        public ?AiProviderSettings $storedWritingConnection,
        public ?AiProviderSettings $writingConnection,
        public array $writingCandidates,
    ) {
    }
}
