<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language\Factory;

use App\Enum\FeedLanguage;
use LanguageDetection\Language;

final readonly class LanguageDetectionFactory
{
    public function create(): Language
    {
        return new Language(array_column(FeedLanguage::cases(), 'value'));
    }
}
