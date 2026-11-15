<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\Support\EntryPlainText;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

/** A social post's language: what its author stated, unless a long text plainly reads as another (#2227). */
final readonly class SocialEntryLanguage
{
    private const int LONG_TEXT_LENGTH = 150;

    public function __construct(
        private LanguageGuess $languageGuess,
    ) {
    }

    public function of(ParsedEntryModel $entry): ?FeedLanguage
    {
        $body = EntryPlainText::of($entry->summary ?? $entry->contentHtml) ?? '';
        if (null === $entry->statedLanguage) {
            return $this->guess($entry, $body);
        }

        $stated = FeedLanguage::fromTag($entry->statedLanguage);
        if (null === $stated || mb_strlen($body) < self::LONG_TEXT_LENGTH) {
            return $stated;
        }

        return $this->guess($entry, $body) ?? $stated;
    }

    private function guess(ParsedEntryModel $entry, string $body): ?FeedLanguage
    {
        return $this->languageGuess->bestFor($entry->headline->text . "\n" . $body);
    }
}
