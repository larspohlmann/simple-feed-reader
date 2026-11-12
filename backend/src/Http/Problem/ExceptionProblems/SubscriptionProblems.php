<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\FeedThrottledOnSubscribeException;
use App\Service\Subscription\Exception\NotAFeedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SubscriptionProblems implements ExceptionProblemsInterface
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
            $exception instanceof NotAFeedException => new ResolvedProblem(new ApiProblem(
                'not_a_feed',
                'Not a feed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'No feed could be read at that address.',
            )),
            $exception instanceof FeedThrottledOnSubscribeException => new ResolvedProblem(new ApiProblem(
                'feed_throttled',
                'Site is rationing requests',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'The site asked us to slow down. Try again in a minute.',
            )),
            default => null,
        };
    }
}
