<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming\GermanLexicon;

use App\Service\Foundation\Stemming\Model\GermanLexiconEntryModel;

interface GermanLexiconInterface
{
    public function findEntry(string $form): ?GermanLexiconEntryModel;
}
