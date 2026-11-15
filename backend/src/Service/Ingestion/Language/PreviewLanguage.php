<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Entity\LinkCardContent;
use App\Entity\LinkLanguage;
use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Support\PreviewText;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PreviewLanguage
{
    public function __construct(
        #[Autowire(service: 'app.card_language_guess')]
        private LanguageGuess $languageGuess,
    ) {
    }

    public function of(LinkCardContent $preview, ?FeedLanguage $statedLanguage): ?LinkLanguage
    {
        if ($statedLanguage !== null) {
            return LinkLanguage::stated($statedLanguage);
        }
        $text = PreviewText::of($preview->title, $preview->description);
        if (!$this->languageGuess->overrulesAStatedLanguage($text)) {
            return null;
        }
        $guess = $this->languageGuess->bestFor($text);

        return $guess === null ? null : LinkLanguage::guessed($guess);
    }
}
