<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\FeedLanguage;

/** A null language is the cut form; the word is what the reader is shown for the stem. */
final readonly class StreamWordModel
{
    public function __construct(
        public ?FeedLanguage $language,
        public string $stem,
        public string $word,
        public float $weight,
        public float $rarity,
    ) {
    }

    #[\NoDiscard]
    public function withWeight(float $weight): self
    {
        return clone($this, ['weight' => $weight]);
    }
}
