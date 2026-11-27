<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Foundation\Stemming\Factory\SnowballStemmerFactory;
use App\Service\Foundation\Stemming\GermanCompoundSplitter;
use App\Service\Foundation\Stemming\GermanLexicon\BundledGermanLexicon;
use App\Service\Foundation\Stemming\WordStemmer;

final class StemmingWiring
{
    public static function stemmer(): WordStemmer
    {
        return new WordStemmer(new SnowballStemmerFactory());
    }

    public static function germanLexicon(): BundledGermanLexicon
    {
        return new BundledGermanLexicon(dirname(__DIR__) . '/Fixtures/german-lexicon');
    }

    public static function compoundSplitter(): GermanCompoundSplitter
    {
        return new GermanCompoundSplitter(self::germanLexicon());
    }

    private function __construct()
    {
    }
}
