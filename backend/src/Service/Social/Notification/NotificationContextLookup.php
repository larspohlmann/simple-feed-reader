<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationContextSource\NotificationContextSourceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NotificationContextLookup
{
    public function __construct(
        #[AutowireLocator('app.social_notification_context')]
        private ContainerInterface $contextSources,
        private NotificationStore $store,
    ) {
    }

    public function completing(SocialAccount $account, NotificationPageModel $page): NotificationPageModel
    {
        $fresh = $this->store->unstored($account, $page);
        if ($fresh === []) {
            return $page;
        }

        $source = $this->contextSources->get($account->getNetwork()->value);
        \assert($source instanceof NotificationContextSourceInterface);

        return $page->withSubjects($source->findSubjects($account, $fresh));
    }
}
