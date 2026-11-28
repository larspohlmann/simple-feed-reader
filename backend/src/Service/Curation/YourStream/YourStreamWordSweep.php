<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\InterestTermSet;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\YourStream\Model\YourStreamWordSweepReportModel;
use App\Service\Curation\YourStream\Pass\RaritySweepPass;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use Psr\Log\LoggerInterface;

/** Refreshes the rarities of every stored interest-term set that has gone stale. */
final readonly class YourStreamWordSweep
{
    public const int UNHURRIED_BUDGET_SECONDS = 3600;

    public function __construct(
        private InterestTermSetRepository $termSets,
        private YourStreamBackground $rarity,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudgetModel $budget): YourStreamWordSweepReportModel
    {
        $deadline = $budget->deadlineFrom($this->clock->now());

        return new YourStreamWordSweepReportModel($this->refreshStaleRarities($deadline));
    }

    private function refreshStaleRarities(\DateTimeImmutable $deadline): int
    {
        $now = $this->clock->now();
        if ($now >= $deadline) {
            return 0;
        }
        $cutoff = $now->modify(\sprintf('-%d hours', YourStreamBackground::REFRESH_HOURS));
        $stale = $this->termSets->findWithStaleRarities($cutoff, $now);
        if ([] === $stale) {
            return 0;
        }
        try {
            $pass = $this->rarity->buildSweepPass();
        } catch (\Throwable $exception) {
            $this->logger->error('Interest-term sweep: reading the rarity window failed.', ['exception' => $exception]);

            return 0;
        }
        $refreshed = 0;
        foreach ($stale as $set) {
            if ($this->clock->now() >= $deadline) {
                break;
            }
            $refreshed += $this->refreshSetRarities($set, $pass) ? 1 : 0;
        }

        return $refreshed;
    }

    /** One account's failure must not cost the others their refresh. */
    private function refreshSetRarities(InterestTermSet $set, RaritySweepPass $pass): bool
    {
        try {
            $this->rarity->refreshRarities($set, $pass);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Interest-term sweep: refreshing an account\'s rarities failed.', [
                'userId' => $set->getUser()->getId(),
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
