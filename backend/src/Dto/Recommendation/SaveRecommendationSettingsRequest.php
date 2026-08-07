<?php

declare(strict_types=1);

namespace App\Dto\Recommendation;

use App\Service\Recommendation\RecommendationSettingsValues;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The client's write shape for a user's recommendation settings row, a 1:1
 * mirror of RecommendationSettingsValues. Blank-guidance normalisation is
 * deliberately not here: RecommendationSettingsWriter owns that decision, not
 * the wire format.
 */
final readonly class SaveRecommendationSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 4000)]
        public ?string $guidancePrompt,
        #[Assert\Range(min: 0, max: 500)]
        public int $favoritesCap,
        #[Assert\Range(min: 0, max: 500)]
        public int $keptCap,
        #[Assert\Range(min: 0, max: 500)]
        public int $viewedCap,
        #[Assert\Range(min: 10, max: 5000)]
        public int $candidatePoolSize,
        #[Assert\Range(min: 1, max: 500)]
        public int $picksLimit,
        #[Assert\Range(min: 4096, max: 2097152)]
        public ?int $contextWindow,
        public bool $debugEnabled,
    ) {
    }

    public function values(): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: $this->guidancePrompt,
            favoritesCap: $this->favoritesCap,
            keptCap: $this->keptCap,
            viewedCap: $this->viewedCap,
            candidatePoolSize: $this->candidatePoolSize,
            picksLimit: $this->picksLimit,
            contextWindow: $this->contextWindow,
            debugEnabled: $this->debugEnabled,
        );
    }
}
