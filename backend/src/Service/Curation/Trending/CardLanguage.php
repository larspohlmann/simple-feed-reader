<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\LanguageGuess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CardLanguage
{
    public function __construct(
        #[Autowire(service: 'app.card_language_guess')]
        private LanguageGuess $languageGuess,
    ) {
    }

    public function resolveLanguage(string $text, ?FeedLanguage $postLanguage): ?FeedLanguage
    {
        if ($postLanguage === null) {
            return $this->languageGuess->bestFor($text);
        }

        return $this->languageGuess->confidentFor($text) ?? $postLanguage;
    }
}
