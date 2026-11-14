<?php

declare(strict_types=1);

namespace App\Service\Subscription\Exception;

final class SubscriptionLimitReachedException extends \RuntimeException
{
    public function __construct(int $limit)
    {
        parent::__construct(sprintf('You can subscribe to at most %d feeds.', $limit));
    }
}
