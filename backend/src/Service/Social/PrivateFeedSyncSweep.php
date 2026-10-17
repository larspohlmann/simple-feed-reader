<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncSkip;
use App\Repository\SocialAccountRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\PrivateFeedSyncBudgetModel;
use App\Service\Social\Model\PrivateFeedSyncOutcomeModel;
use App\Service\Social\Model\PrivateFeedSyncReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final readonly class PrivateFeedSyncSweep
{
    public const string DUE_AFTER = '-1 hour';

    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private PrivateFeedSync $privateFeedSync,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** One account's failure is logged and stamped `failed`; the sweep stops early only when the entity manager stays closed. */
    public function sweep(PrivateFeedSyncBudgetModel $budget): PrivateFeedSyncReportModel
    {
        $report = PrivateFeedSyncReportModel::none();
        if ($budget->isEmpty()) {
            return $report;
        }
        $dueBefore = $this->clock->now()->modify(self::DUE_AFTER);
        foreach ($this->socialAccounts->findIdsDueForSync($dueBefore, $budget->accounts) as $accountId) {
            $account = $this->socialAccounts->find($accountId);
            if (null !== $account) {
                $report = $report->adding($this->syncIsolated($account));
            }
            if (!$this->reopenedEntityManagerIsOpen()) {
                break;
            }
        }

        return $report;
    }

    private function syncIsolated(SocialAccount $account): PrivateFeedSyncOutcomeModel
    {
        $accountId = $account->requireId();
        try {
            return $this->privateFeedSync->sync($account);
        } catch (\Throwable $exception) {
            $this->logger->error('Private feed sync failed for an account.', [
                'socialAccountId' => $accountId,
                'exception' => $exception,
            ]);
            $this->stampFailed($accountId);

            return new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Failed);
        }
    }

    private function stampFailed(int $accountId): void
    {
        try {
            $this->socialAccounts->recordFailedSync($accountId, $this->clock->now());
        } catch (\Throwable $exception) {
            $this->logger->error('Stamping a failed private feed sync failed.', [
                'socialAccountId' => $accountId,
                'exception' => $exception,
            ]);
        }
    }

    private function reopenedEntityManagerIsOpen(): bool
    {
        if (!$this->entityManager->isOpen()) {
            $this->registry->resetManager();
        }

        return $this->entityManager->isOpen();
    }
}
