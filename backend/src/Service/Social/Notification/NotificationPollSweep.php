<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Repository\SocialAccountSyncRepository;
use App\Repository\SocialNotificationPollRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Notification\Model\NotificationPollBudgetModel;
use App\Service\Social\Notification\Model\NotificationPollReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final readonly class NotificationPollSweep
{
    public function __construct(
        private SocialNotificationPollRepository $polls,
        private SocialAccountSyncRepository $socialAccounts,
        private NotificationPoll $notificationPoll,
        private NotificationStore $store,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(NotificationPollBudgetModel $budget): NotificationPollReportModel
    {
        $report = NotificationPollReportModel::none();
        if ($budget->isEmpty()) {
            return $report;
        }
        foreach ($this->polls->findAccountIdsDue($this->clock->now(), $budget->accounts) as $accountId) {
            $account = $this->socialAccounts->find($accountId);
            if (null !== $account) {
                $report = $this->pollIsolated($account, $accountId, $report);
            }
            if (!$this->ensureManagerOpen()) {
                return $report;
            }
        }

        return $report->withPruned($this->store->prune());
    }

    private function pollIsolated(
        SocialAccount $account,
        int $accountId,
        NotificationPollReportModel $report,
    ): NotificationPollReportModel {
        try {
            return $report->addingPolled($this->notificationPoll->poll($account));
        } catch (\Throwable $exception) {
            $this->logger->error('Notification poll failed for an account.', [
                'socialAccountId' => $accountId,
                'exception' => $exception,
            ]);
            if ($this->ensureManagerOpen()) {
                $this->entityManager->clear();
            }
            $this->stampFailed($accountId);

            return $report->addingFailure();
        }
    }

    private function stampFailed(int $accountId): void
    {
        try {
            $account = $this->socialAccounts->find($accountId);
            if (null === $account) {
                return;
            }
            $this->polls->ensureStamp($account)->recordChecked($this->clock->now());
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Stamping a failed notification poll failed.', [
                'socialAccountId' => $accountId,
                'exception' => $exception,
            ]);
        }
    }

    private function ensureManagerOpen(): bool
    {
        if (!$this->entityManager->isOpen()) {
            $this->registry->resetManager();
        }

        return $this->entityManager->isOpen();
    }
}
