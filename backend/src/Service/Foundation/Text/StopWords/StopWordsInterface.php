<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\StopWords;

use App\Enum\FeedLanguage;

interface StopWordsInterface
{
    /** @return list<string> casefolded, diacritics kept */
    public function listWords(FeedLanguage $language): array;

    public function isStopWord(string $casefoldedWord, FeedLanguage $language): bool;
}
