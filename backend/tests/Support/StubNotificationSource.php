<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;

/** A notification source that answers every fetch the same way and remembers whom it was asked for. */
final class StubNotificationSource implements NotificationSourceInterface
{
    /** @var list<string> */
    public array $fetchedIdentities = [];

    /** @param array<string, NotificationPageModel|\Throwable> $answers per identity; others get an empty page */
    public function __construct(private readonly array $answers = [])
    {
    }

    public function fetch(SocialAccount $account): NotificationPageModel
    {
        $this->fetchedIdentities[] = $account->getIdentity();
        $answer = $this->answers[$account->getIdentity()] ?? new NotificationPageModel([], null);
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void
    {
    }
}
