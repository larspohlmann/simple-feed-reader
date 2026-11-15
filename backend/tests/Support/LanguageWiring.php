<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ingestion\Language\StopWords\BundledStopWords;

/** Language services built by hand: the test container hands out none of the ones used once. */
final class LanguageWiring
{
    public static function stopWords(): BundledStopWords
    {
        return new BundledStopWords(dirname(__DIR__, 2) . '/resources/stopwords/stopwords.php');
    }

    private function __construct()
    {
    }
}
