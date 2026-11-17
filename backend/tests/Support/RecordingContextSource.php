<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\NotificationContext;
use App\Entity\SocialAccount;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\NotificationContextSource\NotificationContextSourceInterface;

/** A context source that remembers the remote ids it was handed and puts $adds on every row. */
final class RecordingContextSource implements NotificationContextSourceInterface
{
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(private readonly ?NotificationContext $adds = null)
    {
    }

    public function withContext(SocialAccount $account, array $fresh): array
    {
        $this->asked[] = array_map(static fn (FetchedNotificationModel $fetched): string => $fetched->remoteId, $fresh);
        $adds = $this->adds;
        if ($adds === null) {
            return $fresh;
        }

        return array_map(
            static fn (FetchedNotificationModel $fetched): FetchedNotificationModel => $fetched->withSubject(
                $fetched->subject->withContext($adds),
            ),
            $fresh,
        );
    }
}
