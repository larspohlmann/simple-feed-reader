<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Enum\YourStreamPrecision;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Model\EntryStemsModel;
use App\Service\Curation\YourStream\Model\RarityWindowModel;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\Model\YourStreamExplanationModel;
use App\Service\Curation\YourStream\YourStreamWordBuilder\YourStreamWordBuilderInterface;
use App\Service\Foundation\Clock\NaiveUtcClock;

/** One account's word list built in memory and its recent entries replayed at every precision: tuning without a row. */
final readonly class YourStreamExplainer
{
    public function __construct(
        private InterestArticles $interests,
        private YourStreamWordBuilderInterface $builder,
        private YourStreamBackground $background,
        private YourStreamSourceRepository $sources,
        private YourStreamScorer $scorer,
        private NaiveUtcClock $clock,
    ) {
    }

    public function buildExplanation(int $userId, int $days): YourStreamExplanationModel
    {
        $pass = $this->background->buildSweepPass();
        $words = $this->builder->buildWords($this->interests->collectInputs($userId), $pass);
        $entries = $this->findReplayedEntries($userId, $days);
        $stems = array_map(
            static fn (StreamEntryModel $entry): EntryStemsModel => $pass->stemming->collectStems($entry),
            $entries,
        );
        $variants = [
            YourStreamExplanationModel::WEIGHT_TIMES_RARITY => self::buildStoredStrengths($words),
            YourStreamExplanationModel::FLAT_RARITY => self::buildFlatStrengths($words),
        ];
        $matches = array_map(
            fn (StreamStrengthsModel $strengths): array => $this->tallyMatches($strengths, $stems),
            $variants,
        );

        return new YourStreamExplanationModel($words, $days, \count($entries), $matches);
    }

    /** @return list<StreamEntryModel> newest first, at most a background sample */
    private function findReplayedEntries(int $userId, int $days): array
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', $days));
        $window = new RarityWindowModel($since, ($this->sources->findFirstEntryIdSince($since) ?? 1) - 1);

        return $this->sources->findNewestEntries($userId, $window, PHP_INT_MAX, YourStreamBackground::SAMPLE_ENTRIES);
    }

    /**
     * @param list<EntryStemsModel> $stems
     *
     * @return array<string, int> precision value => matches
     */
    private function tallyMatches(StreamStrengthsModel $strengths, array $stems): array
    {
        $matches = [];
        foreach (YourStreamPrecision::cases() as $precision) {
            $matched = array_filter(
                $stems,
                fn (EntryStemsModel $entry): bool
                    => $this->scorer->findMatch($strengths, $entry, $precision->minimumScore()) !== null,
            );
            $matches[$precision->value] = \count($matched);
        }

        return $matches;
    }

    /** @param list<StreamWordModel> $words */
    private static function buildStoredStrengths(array $words): StreamStrengthsModel
    {
        $strengths = [];
        foreach ($words as $word) {
            $strengths[StreamStrengthsModel::languageKey($word->language)][$word->stem] = $word->weight * $word->rarity;
        }

        return StreamStrengthsModel::fromStrengths($strengths);
    }

    /** @param list<StreamWordModel> $words */
    private static function buildFlatStrengths(array $words): StreamStrengthsModel
    {
        $raritiesByLanguageKey = [];
        foreach ($words as $word) {
            $raritiesByLanguageKey[StreamStrengthsModel::languageKey($word->language)][] = $word->rarity;
        }
        $meanRarities = array_map(
            static fn (array $rarities): float => array_sum($rarities) / \count($rarities),
            $raritiesByLanguageKey,
        );
        $strengths = [];
        foreach ($words as $word) {
            $languageKey = StreamStrengthsModel::languageKey($word->language);
            $strengths[$languageKey][$word->stem] = $word->weight * $meanRarities[$languageKey];
        }

        return StreamStrengthsModel::fromStrengths($strengths);
    }
}
