<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Foundation\Stemming\Factory\SnowballStemmerFactory;
use App\Service\Foundation\Stemming\WordStemmer;

final class StemmingWiring
{
    public static function stemmer(): WordStemmer
    {
        return new WordStemmer(new SnowballStemmerFactory());
    }

    private function __construct()
    {
    }
}
