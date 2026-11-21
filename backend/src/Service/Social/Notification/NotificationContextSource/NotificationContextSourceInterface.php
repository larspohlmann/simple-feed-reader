<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationContextSource;

use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_notification_context')]
interface NotificationContextSourceInterface
{
    /**
     * Only rows whose subject was completed get a key, its value built on the row's existing subject; no call is made
     * when no row has references. A failed lookup, a missing token scope included, leaves the key out; only a
     * rejected credential propagates.
     *
     * @param non-empty-list<FetchedNotificationModel> $fresh
     * @return array<array-key, NotificationSubject> by remote id
     *
     * @throws SocialCredentialsRejectedException
     */
    public function findSubjects(SocialAccount $account, array $fresh): array;
}
