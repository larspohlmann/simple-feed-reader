<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Prompt;

use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Llm\Prompt\Model\InterestTermParseResultModel;
use App\Service\Curation\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermModel;

/**
 * Turns one interest-term reply into weighted terms. A term in a language that was not asked for, with a blank or
 * overlong phrase or a non-numeric weight, is dropped; a reply left with no term is unusable.
 */
final readonly class InterestTermParser
{
    private const int MAX_TERM_LENGTH = 80;

    private const int MIN_WEIGHT = 1;

    private const int MAX_WEIGHT = 10;

    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    /** @param list<FeedLanguage> $languages */
    public function parse(string $content, array $languages): InterestTermParseResultModel
    {
        $decoded = $this->decoder->decode($content);
        $items = $decoded['terms'] ?? null;

        if (!\is_array($items)) {
            return InterestTermParseResultModel::unusable();
        }

        $terms = [];
        $keptPerLanguage = [];

        foreach ($items as $item) {
            $term = $this->buildTerm($item, $languages);

            $kept = null === $term ? 0 : $keptPerLanguage[$term->language->value] ?? 0;

            if (null === $term || $kept >= RecommendationPromptText::MAX_TERMS_PER_LANGUAGE) {
                continue;
            }

            $keptPerLanguage[$term->language->value] = $kept + 1;
            $terms[] = $term;
        }

        return [] === $terms ? InterestTermParseResultModel::unusable() : InterestTermParseResultModel::usable($terms);
    }

    /** @param list<FeedLanguage> $languages */
    private function buildTerm(mixed $item, array $languages): ?InterestTermModel
    {
        if (!\is_array($item)) {
            return null;
        }

        $language = \is_string($item['language'] ?? null) ? FeedLanguage::tryFrom($item['language']) : null;
        $phrase = \is_string($item['term'] ?? null) ? trim($item['term']) : '';
        $weight = $item['weight'] ?? null;

        if (null === $language || !\in_array($language, $languages, true) || !is_numeric($weight)) {
            return null;
        }

        $length = mb_strlen($phrase);
        if ($length < 1 || $length > self::MAX_TERM_LENGTH) {
            return null;
        }

        $clamped = max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, (int) $weight));

        return new InterestTermModel($language, $phrase, $clamped / self::MAX_WEIGHT);
    }
}
