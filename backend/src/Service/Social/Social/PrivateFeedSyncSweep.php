<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncSkip;
use App\Repository\SocialAccountSyncRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Persistence\ManagerReopener;
use App\Service\Social\Social\Model\PrivateFeedSyncBudgetModel;
use App\Service\Social\Social\Model\PrivateFeedSyncOutcomeModel;
use App\Service\Social\Social\Model\PrivateFeedSyncReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class PrivateFeedSyncSweep
{
    public function __construct(
        private SocialAccountSyncRepository $socialAccounts,
        private PrivateFeedSync $privateFeedSync,
        private EntityManagerInterface $entityManager,
        private ManagerReopener $managerReopener,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(PrivateFeedSyncBudgetModel $budget): PrivateFeedSyncReportModel
    {
        $report = PrivateFeedSyncReportModel::none();
        if ($budget->isEmpty()) {
            return $report;
        }
        foreach ($this->socialAccounts->findIdsDueForSync($this->clock->now(), $budget->accounts) as $accountId) {
            $account = $this->socialAccounts->find($accountId);
            if (null !== $account) {
                $report = $report->adding($this->syncIsolated($account));
            }
            if (!$this->isManagerOpenAfterReopening()) {
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
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

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

    private function isManagerOpenAfterReopening(): bool
    {
        $this->managerReopener->ensureManagerOpen();

        return $this->entityManager->isOpen();
    }
}
