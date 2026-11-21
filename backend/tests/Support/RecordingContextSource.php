<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\NotificationContext;
use App\Entity\SocialAccount;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\NotificationContextSource\NotificationContextSourceInterface;

final class RecordingContextSource implements NotificationContextSourceInterface
{
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(private readonly ?NotificationContext $adds = null)
    {
    }

    public function findSubjects(SocialAccount $account, array $fresh): array
    {
        $remoteIds = array_map(static fn (FetchedNotificationModel $fetched): string => $fetched->remoteId, $fresh);
        $this->asked[] = $remoteIds;
        if ($this->adds === null) {
            return [];
        }

        $subjects = [];
        foreach ($fresh as $row) {
            $subjects[$row->remoteId] = $row->subject->withContext($this->adds);
        }

        return $subjects;
    }
}
