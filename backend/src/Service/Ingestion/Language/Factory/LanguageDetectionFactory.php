<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language\Factory;

use LanguageDetection\Language;

final readonly class LanguageDetectionFactory
{
    public function create(): Language
    {
        // Every profile, not just the four: with only those loaded, Italian ranked as Spanish and Russian as German.
        return new Language();
    }
}
