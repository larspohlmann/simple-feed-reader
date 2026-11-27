<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

use App\Enum\FeedLanguage;

final readonly class InterestTermModel
{
    public function __construct(public FeedLanguage $language, public string $phrase, public float $weight)
    {
    }
}
