<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Repository\ForYouStreamSourceRepository;
use App\Repository\InterestTermRepository;
use App\Service\Curation\Recommendation\Stream\Factory\EntryStemmingFactory;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Pass\EntryStemming;
use App\Service\Curation\Recommendation\Stream\Pass\RarityTally;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;

/** Weighs each term by how rare its stem is among the account's For you entries of the last thirty days. */
final readonly class InterestTermRarity
{
    public const int WINDOW_DAYS = 30;
    public const int COUNTED_ENTRIES_FLOOR = 500;
    public const int CHUNK = 1000;
    public const int REFRESH_HOURS = 24;

    public function __construct(
        private ForYouStreamSourceRepository $sources,
        private InterestTermRepository $storedTerms,
        private EntryStemmingFactory $stemmingFactory,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return bool false when the deadline cut the walk short, leaving the set unstamped for the next sweep */
    public function refreshRarities(InterestTermSet $set, ?\DateTimeImmutable $deadline = null): bool
    {
        $userId = $set->getUser()->requireId();
        $terms = $this->storedTerms->findForUser($userId);
        $now = $this->clock->now();
        if ($terms !== [] && !$this->weighTerms($userId, $terms, $now, $deadline)) {
            return false;
        }
        $set->recordRaritiesRefreshed($now);
        $this->entityManager->flush();

        return true;
    }

    /** @param list<InterestTerm> $terms */
    private function weighTerms(
        int $userId,
        array $terms,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $deadline,
    ): bool {
        $since = $now->modify(\sprintf('-%d days', self::WINDOW_DAYS));
        $tally = $this->tally($userId, $since, $terms, $deadline);
        if (null === $tally) {
            return false;
        }

        foreach ($terms as $term) {
            $key = InterestTermsModel::languageKey($term->getLanguage());
            $counted = max($tally->countEntries($key), self::COUNTED_ENTRIES_FLOOR);
            $term->recordRarity(log(($counted + 2) / ($tally->countDocuments($key, $term->getStem()) + 1)));
        }

        return true;
    }

    /** @param list<InterestTerm> $terms */
    private function tally(
        int $userId,
        \DateTimeImmutable $since,
        array $terms,
        ?\DateTimeImmutable $deadline,
    ): ?RarityTally {
        $wanted = [];
        foreach ($terms as $term) {
            $wanted[InterestTermsModel::languageKey($term->getLanguage())][$term->getStem()] = true;
        }
        $tally = new RarityTally($wanted);
        $stemming = $this->stemmingFactory->create();
        $afterId = ($this->sources->findFirstEntryIdSince($since) ?? 1) - 1;
        do {
            $entries = $this->sources->findRecentEntries($userId, $since, $afterId, self::CHUNK);
            foreach ($entries as $entry) {
                $tally->count($stemming->collectStems($entry));
                $afterId = $entry->id;
            }
            if (\count($entries) === self::CHUNK && null !== $deadline && $this->clock->now() >= $deadline) {
                return null;
            }
        } while (\count($entries) === self::CHUNK);

        return $tally;
    }
}
