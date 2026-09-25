<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SubscriptionProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof SubscriptionLimitReachedException => new ResolvedProblem(new ApiProblem(
                'subscription_limit_reached',
                'Subscription limit reached',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof AlreadySubscribedException => new ResolvedProblem(new ApiProblem(
                'already_subscribed',
                'Already subscribed to that feed',
                Response::HTTP_CONFLICT,
            )),
            default => null,
        };
    }
}
