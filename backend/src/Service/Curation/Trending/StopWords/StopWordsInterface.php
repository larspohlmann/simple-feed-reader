<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StopWords;

use App\Enum\FeedLanguage;

interface StopWordsInterface
{
    /** @return list<string> casefolded, diacritics kept */
    public function wordsOf(FeedLanguage $language): array;
}
