<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Enum\YourStreamPrecision;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Model\EntryStemsModel;
use App\Service\Curation\YourStream\Model\RarityWindowModel;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;
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
        $matches = $this->tallyMatches(StreamStrengthsModel::fromWords($words), $stems);

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
}
