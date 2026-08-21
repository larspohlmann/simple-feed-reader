<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The outcome of parsing one distillation reply. `usable` is what
 * RecommendationRunAdvancer branches on: a usable profile is carried into the
 * batch and consolidation phases; an unusable one triggers a retry with a
 * corrective message, mirroring PickParseResult and DuplicateParseResult.
 */
final readonly class ProfileParseResult
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
