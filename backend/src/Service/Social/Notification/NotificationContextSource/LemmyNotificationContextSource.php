<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationContextSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyNotificationContextSource implements NotificationContextSourceInterface
{
    public function withContext(SocialAccount $account, array $fresh): array
    {
        return $fresh;
    }
}
