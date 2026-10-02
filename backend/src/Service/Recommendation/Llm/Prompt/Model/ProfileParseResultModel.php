<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Prompt\Model;

/**
 * One distillation reply's outcome: RecommendationProfileDistiller stores a usable profile, and an unusable reply is
 * retried with a corrective message.
 */
final readonly class ProfileParseResultModel
{
    private function __construct(
        public ?string $profile,
        public bool $usable,
    ) {
    }

    public static function usable(string $profile): self
    {
        return new self($profile, true);
    }

    public static function unusable(): self
    {
        return new self(null, false);
    }
}
