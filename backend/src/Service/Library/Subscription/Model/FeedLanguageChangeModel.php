<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\Model;

use App\Enum\FeedLanguage;

/** A null language is Automatic: the feed's next refresh guesses it again. */
final readonly class FeedLanguageChangeModel
{
    public function __construct(public ?FeedLanguage $language)
    {
    }
}
