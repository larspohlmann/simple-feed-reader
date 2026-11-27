<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming\Factory;

use App\Enum\FeedLanguage;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

final readonly class SnowballStemmerFactory
{
    public function create(FeedLanguage $language): Stemmer
    {
        return StemmerFactory::create($language->value);
    }
}
