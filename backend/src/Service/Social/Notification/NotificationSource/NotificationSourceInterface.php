<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationSource;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's notifications for an account, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_notification_source')]
interface NotificationSourceInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function fetch(SocialAccount $account): NotificationPageModel;

    /**
     * Moves the network's marker to the newest of $unseen; $marked is called for each row now held as seen.
     *
     * @param non-empty-list<SocialNotification> $unseen all of $account, ordered by id
     * @param \Closure(SocialNotification): void $marked
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void;
}
