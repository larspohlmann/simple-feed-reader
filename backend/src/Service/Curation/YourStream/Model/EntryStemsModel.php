<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\FeedLanguage;

final readonly class EntryStemsModel
{
    /** @param array<array-key, float> $positionWeights stem => its weight by where it stands in the entry */
    public function __construct(public ?FeedLanguage $language, public array $positionWeights)
    {
    }
}
