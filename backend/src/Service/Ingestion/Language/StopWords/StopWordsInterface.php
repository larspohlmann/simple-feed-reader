<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language\StopWords;

use App\Enum\FeedLanguage;

interface StopWordsInterface
{
    /** @return list<string> casefolded, diacritics kept */
    public function wordsOf(FeedLanguage $language): array;
}
