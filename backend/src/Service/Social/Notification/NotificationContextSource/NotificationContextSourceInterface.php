<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationContextSource;

use App\Entity\SocialAccount;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_notification_context')]
interface NotificationContextSourceInterface
{
    /**
     * Returns the same rows in the same order, with context added where found; a row with empty references is
     * returned untouched, and no call is made when no row has references. A failed lookup, a missing token scope
     * included, leaves its rows without that context; only a rejected credential propagates.
     *
     * @param non-empty-list<FetchedNotificationModel> $fresh
     * @return list<FetchedNotificationModel>
     *
     * @throws SocialCredentialsRejectedException
     */
    public function withContext(SocialAccount $account, array $fresh): array;
}
