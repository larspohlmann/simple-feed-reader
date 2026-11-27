<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\Factory\SnowballStemmerFactory;
use App\Service\Foundation\Stemming\Support\WordStems;
use Wamania\Snowball\Stemmer\Stemmer;

/** A word with a language gets that language's Snowball stem; one without keeps the six-character cut (#2227). */
final readonly class WordStemmer
{
    /** @var array<string, Stemmer> language => its stemmer */
    private array $stemmers;

    public function __construct(SnowballStemmerFactory $stemmerFactory)
    {
        $stemmers = [];
        foreach (FeedLanguage::cases() as $language) {
            $stemmers[$language->value] = $stemmerFactory->create($language);
        }
        $this->stemmers = $stemmers;
    }

    public function stem(string $word, ?FeedLanguage $language): string
    {
        if ($language === null) {
            return WordStems::cut($word);
        }

        return WordStems::bare($this->stemmers[$language->value]->stem($word));
    }
}
