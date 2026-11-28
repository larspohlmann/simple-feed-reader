<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Repository\InterestTermRepository;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\Model\RarityWindowModel;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;
use App\Service\Curation\YourStream\Pass\RaritySweepPass;
use App\Service\Curation\YourStream\Pass\RarityTally;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Weighs each term by how rare its stem is among the account's newest For you entries of the last thirty days. The
 * sample cap bounds one account's walk, so a refresh always finishes.
 */
final readonly class YourStreamBackground
{
    public const int WINDOW_DAYS = 30;
    public const int SAMPLE_ENTRIES = 5000;
    public const int COUNTED_ENTRIES_FLOOR = 500;
    public const int CHUNK = 1000;
    public const int REFRESH_HOURS = 24;

    public function __construct(
        private YourStreamSourceRepository $sources,
        private InterestTermRepository $storedTerms,
        private EntryStemmingFactory $stemmingFactory,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function buildSweepPass(): RaritySweepPass
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', self::WINDOW_DAYS));
        $afterId = ($this->sources->findFirstEntryIdSince($since) ?? 1) - 1;

        return new RaritySweepPass($this->stemmingFactory->create(), new RarityWindowModel($since, $afterId));
    }

    public function refreshRarities(InterestTermSet $set, RaritySweepPass $pass): void
    {
        $userId = $set->getUser()->requireId();
        $terms = $this->storedTerms->findForUser($userId);
        if ($terms !== []) {
            $this->weighTerms($terms, $this->tally($userId, $terms, $pass));
        }
        $set->recordRaritiesRefreshed($this->clock->now());
        $this->entityManager->flush();
    }

    /** @param list<InterestTerm> $terms */
    private function weighTerms(array $terms, RarityTally $tally): void
    {
        foreach ($terms as $term) {
            $key = StreamStrengthsModel::languageKey($term->getLanguage());
            $counted = max($tally->countEntries($key), self::COUNTED_ENTRIES_FLOOR);
            $term->recordRarity(log(($counted + 2) / ($tally->countDocuments($key, $term->getStem()) + 1)));
        }
    }

    /** @param list<InterestTerm> $terms */
    private function tally(int $userId, array $terms, RaritySweepPass $pass): RarityTally
    {
        $wanted = [];
        foreach ($terms as $term) {
            $wanted[StreamStrengthsModel::languageKey($term->getLanguage())][$term->getStem()] = true;
        }
        $tally = new RarityTally($wanted);
        $beforeId = PHP_INT_MAX;
        $remaining = self::SAMPLE_ENTRIES;
        do {
            $limit = min(self::CHUNK, $remaining);
            $entries = $this->sources->findNewestEntries($userId, $pass->window, $beforeId, $limit);
            foreach ($entries as $entry) {
                $tally->count($pass->stemming->collectStems($entry));
                $beforeId = $entry->id;
            }
            $remaining -= \count($entries);
        } while ($remaining > 0 && \count($entries) === $limit);

        return $tally;
    }
}
