<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\SocialEntryLanguage;
use App\Service\Ingestion\Language\StopWords\BundledStopWords;
use App\Service\Ingestion\Language\StopWordVote;

/** Language services built by hand: the test container hands out none of the ones used once. */
final class LanguageWiring
{
    public static function stopWords(): BundledStopWords
    {
        return new BundledStopWords(dirname(__DIR__, 2) . '/resources/stopwords/stopwords.php');
    }

    public static function socialEntryLanguage(): SocialEntryLanguage
    {
        return new SocialEntryLanguage(
            new LanguageGuess(new LanguageDetectionFactory()->create()),
            new StopWordVote(self::stopWords()),
        );
    }

    private function __construct()
    {
    }
}
