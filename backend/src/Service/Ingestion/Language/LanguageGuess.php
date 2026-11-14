<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use LanguageDetection\Language;

/** The detector's top language with no confidence floor: a wrong guess costs little where it is used (#2227). */
final readonly class LanguageGuess
{
    public function __construct(
        private Language $detection,
    ) {
    }

    public function bestFor(string $text): ?FeedLanguage
    {
        if (preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }

        return FeedLanguage::tryFrom((string) array_key_first($this->detection->detect($text)->close()));
    }
}
