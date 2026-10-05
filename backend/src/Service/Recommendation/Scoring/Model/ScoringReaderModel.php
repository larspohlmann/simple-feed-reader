<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;

final readonly class ScoringReaderModel
{
    /** @param list<ArticleLineModel> $favorites newest first */
    public function __construct(
        public string $profile,
        public ?string $guidance,
        public array $favorites,
    ) {
    }
}
