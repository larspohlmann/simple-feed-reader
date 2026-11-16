<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Model;

final readonly class NotificationListPageModel
{
    /**
     * @param list<NotificationGroupModel> $groups
     * @param list<string>                 $scopeMissingHandles accounts whose token cannot read notifications
     */
    public function __construct(
        public array $groups,
        public ?int $nextCursor,
        public array $scopeMissingHandles,
    ) {
    }
}
