<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\Support\EntryPlainText;
use App\Service\Ingestion\Language\Model\LanguageVerdictModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use LanguageDetection\Language;

final readonly class FeedLanguageResolver
{
    private const int TEXT_LIMIT = 4000;
    private const int MINIMUM_WORDS = 40;
    private const float MINIMUM_SCORE = 0.40;
    private const float MINIMUM_LEAD = 0.11;

    public function __construct(
        private Language $detection,
    ) {
    }

    public function verdictFor(ParsedFeedModel $parsed): ?LanguageVerdictModel
    {
        $detected = $this->confidentlyDetected($parsed->entries);
        $declaredTag = trim($parsed->declaredLanguage ?? '');

        if ('' === $declaredTag) {
            return null === $detected ? null : new LanguageVerdictModel($detected);
        }

        return new LanguageVerdictModel($detected ?? FeedLanguage::fromTag($declaredTag));
    }

    /** @param list<ParsedEntryModel> $entries */
    private function confidentlyDetected(array $entries): ?FeedLanguage
    {
        $text = self::detectionText($entries);
        if (preg_match_all('/\p{L}+/u', $text) < self::MINIMUM_WORDS) {
            return null;
        }

        $scores = array_filter($this->detection->detect($text)->close(), is_float(...));
        [$best, $runnerUp] = array_values($scores);

        if ($best < self::MINIMUM_SCORE || $best - $runnerUp < self::MINIMUM_LEAD) {
            return null;
        }

        return FeedLanguage::tryFrom((string) array_key_first($scores));
    }

    /** @param list<ParsedEntryModel> $entries */
    private static function detectionText(array $entries): string
    {
        $passages = [];
        foreach ($entries as $entry) {
            $passages[] = $entry->headline->text;
            $passages[] = EntryPlainText::of($entry->summary) ?? '';
        }

        return mb_substr(implode("\n", $passages), 0, self::TEXT_LIMIT);
    }
}
