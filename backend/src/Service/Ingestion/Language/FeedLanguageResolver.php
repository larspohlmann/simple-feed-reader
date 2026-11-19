<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Entity\Feed;
use App\Enum\FeedLanguage;
use App\Enum\SourceFormat;
use App\Service\Foundation\Text\Support\EntryPlainText;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;

final readonly class FeedLanguageResolver
{
    private const int TEXT_LIMIT = 4000;

    public function __construct(
        private LanguageGuess $guess,
    ) {
    }

    public function languageFor(Feed $feed, ParsedFeedModel $parsed): ?FeedLanguage
    {
        if (null !== $feed->getLanguage() || !SourceFormat::carriesFeedLanguage($feed->getSourceFormat())) {
            return $feed->getLanguage();
        }

        return FeedLanguage::fromTag($parsed->declaredLanguage ?? '')
            ?? $this->guess->bestFor(self::guessText($parsed));
    }

    private static function guessText(ParsedFeedModel $parsed): string
    {
        $passages = [];
        foreach ($parsed->entries as $entry) {
            $passages[] = $entry->headline->text;
            $passages[] = EntryPlainText::fromHtml($entry->summary) ?? '';
        }

        return mb_substr(implode("\n", $passages), 0, self::TEXT_LIMIT);
    }
}
