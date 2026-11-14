<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncSkip;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\PrivateFeedSyncOutcomeModel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;

final readonly class PrivateFeedSyncSkips
{
    public function __construct(
        private NaiveUtcClock $clock,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function record(SocialAccount $account, PrivateFeedSyncSkip $skip): PrivateFeedSyncOutcomeModel
    {
        $account->recordSkippedSync($this->clock->now(), $skip);
        $this->entityManager->flush();

        return new PrivateFeedSyncOutcomeModel(skip: $skip);
    }

    /**
     * A rollback leaves the failed check's changes in memory (a rescue's cleared flags among them), so they are
     * dropped before the skip is written on a freshly loaded account. Every entity the caller holds is detached.
     */
    public function recordFailure(SocialAccount $account, \Throwable $failure): PrivateFeedSyncOutcomeModel
    {
        $accountId = $account->requireId();
        $this->logger->error('Syncing a social account\'s lists and feeds failed.', [
            'socialAccountId' => $accountId,
            'exception' => $failure,
        ]);
        $manager = $this->emptiedManager();
        $reloaded = $manager->find(SocialAccount::class, $accountId);
        if (null !== $reloaded) {
            $reloaded->recordSkippedSync($this->clock->now(), PrivateFeedSyncSkip::Failed);
            $manager->flush();
        }

        return new PrivateFeedSyncOutcomeModel(skip: PrivateFeedSyncSkip::Failed);
    }

    private function emptiedManager(): ObjectManager
    {
        if (!$this->entityManager->isOpen()) {
            return $this->registry->resetManager();
        }
        $this->entityManager->clear();

        return $this->entityManager;
    }
}
