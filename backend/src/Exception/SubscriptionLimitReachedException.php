<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class SubscriptionLimitReachedException extends ApiException
{
    public function __construct(int $limit)
    {
        parent::__construct(
            'subscription_limit_reached',
            Response::HTTP_CONFLICT,
            'Subscription limit reached',
            sprintf('You can subscribe to at most %d feeds.', $limit),
        );
    }
}
