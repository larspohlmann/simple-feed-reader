<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\FeedLanguage;

final readonly class EntryStemsModel
{
    /**
     * @param array<array-key, float>        $positionWeights stem => its weight by where it stands in the entry
     * @param array<array-key, list<string>> $stemWords       stem => the words, as the entry spells them, it came from
     */
    public function __construct(
        public ?FeedLanguage $language,
        public array $positionWeights,
        public array $stemWords = [],
    ) {
    }
}
