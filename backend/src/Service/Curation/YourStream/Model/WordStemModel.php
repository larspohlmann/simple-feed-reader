<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\FeedLanguage;

/** A null language is the cut form. */
final readonly class WordStemModel
{
    public function __construct(public ?FeedLanguage $language, public string $stem, public float $weight)
    {
    }
}
