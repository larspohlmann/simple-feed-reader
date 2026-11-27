<?php

declare(strict_types=1);

namespace App\Service\Library\Label\Model;

final readonly class LabelTalliesModel
{
    /** @param list<LabelTallyModel> $labels */
    public function __construct(
        public array $labels,
        public int $unlabeledFavoriteCount,
        public int $unlabeledKeptCount,
    ) {
    }
}
