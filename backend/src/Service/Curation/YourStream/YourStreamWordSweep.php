<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\User;
use App\Repository\YourStreamStateRepository;
use App\Service\Curation\YourStream\Model\YourStreamWordSweepReportModel;
use App\Service\Curation\YourStream\Pass\RaritySweepPass;
use App\Service\Curation\YourStream\YourStreamWordBuilder\YourStreamWordBuilderInterface;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Checks each enabled account's interests hourly; rebuilds its word list when they changed, and daily regardless. */
final readonly class YourStreamWordSweep
{
    public const int CHECK_INTERVAL_MINUTES = 60;
    public const int REBUILD_HOURS = 24;
    public const int UNHURRIED_BUDGET_SECONDS = 3600;

    public function __construct(
        private YourStreamStateRepository $states,
        private InterestArticles $interests,
        private YourStreamWordBuilderInterface $builder,
        private YourStreamBackground $background,
        private YourStreamWordStore $store,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function sweep(SweepBudgetModel $budget): YourStreamWordSweepReportModel
    {
        $now = $this->clock->now();
        $deadline = $budget->deadlineFrom($now);
        $due = $this->states->findUserIdsDueForCheck(
            $now->modify(\sprintf('-%d minutes', self::CHECK_INTERVAL_MINUTES)),
            $now,
        );
        $report = YourStreamWordSweepReportModel::forDue(\count($due));
        if ([] === $due || $now >= $deadline) {
            return $report;
        }
        $pass = $this->background->buildSweepPass();
        foreach ($due as $userId) {
            if ($this->clock->now() >= $deadline) {
                break;
            }
            $report = $this->refreshIsolated($userId, $pass, $report);
            if (!$this->entityManager->isOpen()) {
                break;
            }
            $this->entityManager->clear();
        }

        return $report;
    }

    private function refreshIsolated(
        int $userId,
        RaritySweepPass $pass,
        YourStreamWordSweepReportModel $report,
    ): YourStreamWordSweepReportModel {
        try {
            return $this->refreshAccount($userId, $pass, $report);
        } catch (\Throwable $exception) {
            $this->logger->error('Your stream word sweep failed for an account; it is checked again next hour.', [
                'userId' => $userId,
                'exception' => $exception,
            ]);

            return $report->withOneMoreFailed();
        }
    }

    /** The check is stamped before the build, so an account whose build fails waits an hour like the rest. */
    private function refreshAccount(
        int $userId,
        RaritySweepPass $pass,
        YourStreamWordSweepReportModel $report,
    ): YourStreamWordSweepReportModel {
        $user = $this->entityManager->find(User::class, $userId);
        if (null === $user) {
            return $report;
        }
        $state = $this->store->ensureState($user);
        $this->store->recordChecked($state);
        $inputs = $this->interests->collectInputs($userId);
        $fingerprint = $inputs->fingerprint();
        $builtAfter = $this->clock->now()->modify(\sprintf('-%d hours', self::REBUILD_HOURS));
        if ($state->isBuiltFrom($fingerprint, $builtAfter)) {
            return $report->withOneMoreChecked();
        }
        $this->store->replaceWords($state, $fingerprint, $this->builder->buildWords($inputs, $pass));

        return $report->withOneMoreBuilt();
    }
}
