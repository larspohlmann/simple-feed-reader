<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Prompt\Model;

use App\Service\Curation\Recommendation\Stream\Model\InterestTermModel;

final readonly class InterestTermParseResultModel
{
    /** @param list<InterestTermModel> $terms */
    private function __construct(
        public array $terms,
        public bool $usable,
    ) {
    }

    /** @param list<InterestTermModel> $terms */
    public static function usable(array $terms): self
    {
        return new self($terms, true);
    }

    public static function unusable(): self
    {
        return new self([], false);
    }
}
