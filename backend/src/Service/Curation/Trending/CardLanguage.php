<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\LanguageGuess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CardLanguage
{
    private const int LONG_TEXT_LENGTH = 150;

    public function __construct(
        #[Autowire(service: 'app.trending.card_language_guess')]
        private LanguageGuess $languageGuess,
    ) {
    }

    public function of(string $text, ?FeedLanguage $postLanguage): ?FeedLanguage
    {
        if ($postLanguage !== null && mb_strlen($text) < self::LONG_TEXT_LENGTH) {
            return $postLanguage;
        }

        return $this->languageGuess->bestFor($text) ?? $postLanguage;
    }
}
