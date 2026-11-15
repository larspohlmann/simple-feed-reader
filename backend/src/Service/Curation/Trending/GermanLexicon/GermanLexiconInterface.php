<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\GermanLexicon;

use App\Service\Curation\Trending\Model\GermanLexiconEntryModel;

interface GermanLexiconInterface
{
    /** None for a form the lexicon does not hold. */
    public function entryOf(string $form): ?GermanLexiconEntryModel;
}
