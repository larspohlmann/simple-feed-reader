<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Operations\Worker\Message\PollSocialNotifications;
use App\Service\Social\Notification\Model\NotificationPollBudgetModel;
use App\Service\Social\Notification\NotificationPollSweep;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PollSocialNotificationsHandler
{
    private const int ACCOUNTS = 20;

    public function __construct(
        private NotificationPollSweep $notificationPollSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollSocialNotifications $message): void
    {
        $report = $this->notificationPollSweep->sweep(NotificationPollBudgetModel::accounts(self::ACCOUNTS));
        $this->logger->info('Worker notification poll finished.', ['report' => $report->toLogContext()]);
    }
}
