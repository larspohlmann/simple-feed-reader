<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\GermanLexicon;

use App\Service\Curation\Trending\Model\GermanLexiconEntryModel;

interface GermanLexiconInterface
{
    public function findEntry(string $form): ?GermanLexiconEntryModel;
}
