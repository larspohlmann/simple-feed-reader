<?php

declare(strict_types=1);

namespace App\Dto\Trending;

use App\Service\Curation\StoryAi\Model\StorySettingsChangeModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveStorySettingsRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $writingConnectionId,
        public bool $titlesEnabled,
    ) {
    }

    public function toChange(): StorySettingsChangeModel
    {
        return new StorySettingsChangeModel($this->writingConnectionId, $this->titlesEnabled);
    }
}
