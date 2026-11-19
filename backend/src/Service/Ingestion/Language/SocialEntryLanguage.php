<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\Support\EntryPlainText;
use App\Service\Ingestion\Language\Support\PreviewText;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

/**
 * A social post's language: what its author stated, unless a long text plainly reads as another (#2227);
 * a short post stating none that the detector reads as none of the four takes its stopwords' language (#2243).
 */
final readonly class SocialEntryLanguage
{
    public function __construct(
        private LanguageGuess $languageGuess,
        private StopWordVote $stopWordVote,
    ) {
    }

    public function of(ParsedEntryModel $entry): ?FeedLanguage
    {
        $body = EntryPlainText::fromHtml($entry->summary ?? $entry->contentHtml) ?? '';
        if (null === $entry->statedLanguage) {
            return $this->guess($entry, $body);
        }

        $stated = FeedLanguage::fromTag($entry->statedLanguage);
        if (null === $stated || !$this->languageGuess->readsReliably($body)) {
            return $stated;
        }

        return $this->guess($entry, $body) ?? $stated;
    }

    private function guess(ParsedEntryModel $entry, string $body): ?FeedLanguage
    {
        $text = PreviewText::joined($entry->headline->text, $body);
        $guess = $this->languageGuess->bestFor($text);
        if (null !== $guess) {
            return $guess;
        }
        if ($this->languageGuess->readsReliably($body)) {
            return null;
        }

        // A derived headline repeats the body's first sentence: counted twice, it would break the vote's ties.
        return $this->stopWordVote->languageOf($entry->headline->derived ? $body : $text);
    }
}
