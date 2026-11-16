<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;

/** A notification source that remembers the rows it was asked to mark, and can fail after marking some. */
final class MarkingNotificationSource implements NotificationSourceInterface
{
    /** @var array<string, list<int>> account identity => the row ids it was handed */
    public array $handed = [];

    public function __construct(
        private readonly ?int $markedBeforeFailing = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function fetch(SocialAccount $account): NotificationPageModel
    {
        return new NotificationPageModel([], null);
    }

    public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void
    {
        $this->handed[$account->getIdentity()] = array_map(
            static fn (SocialNotification $row): int => $row->requireId(),
            $unseen,
        );
        foreach ($unseen as $index => $row) {
            if ($index === $this->markedBeforeFailing && $this->failure !== null) {
                throw $this->failure;
            }
            $marked($row);
        }
    }
}
