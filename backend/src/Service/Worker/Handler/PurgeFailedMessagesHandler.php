<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Repository\FailedMessageRepository;
use App\Service\Worker\Message\PurgeFailedMessages;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Daily housekeeping (#311): a stuck worker must not grow the failure transport without bound. */
#[AsMessageHandler]
final readonly class PurgeFailedMessagesHandler
{
    private const int RETENTION_DAYS = 30;

    public function __construct(
        private FailedMessageRepository $failedMessages,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeFailedMessages $message): void
    {
        $this->failedMessages->deleteFailedBefore(
            $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS)),
        );
    }
}
