<?php

declare(strict_types=1);

namespace App\Service\Content\Comments;

use App\Entity\User;
use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\CommentsEndpointModel;
use App\Service\Outbound\Fetch\Exception\FeedThrottledException;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Outbound\Fetch\HostThrottle;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class CommentsEndpointFetcher
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private HostThrottle $hostThrottle,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    public function body(string $url, CommentsEndpointModel $endpoint, User $user): string
    {
        return $this->guarded($endpoint, $user, fn (): string => $this->fetcher->fetch($url)->modifiedBody());
    }

    /** @param \Closure(): string $fetch */
    public function guarded(CommentsEndpointModel $endpoint, User $user, \Closure $fetch): string
    {
        $wait = $this->hostThrottle->remainingSeconds($endpoint->throttleKey);
        if ($wait > 0) {
            throw new CommentsThrottledException($endpoint->throttledMessage, $wait);
        }
        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $user);

        try {
            return $fetch();
        } catch (FeedThrottledException $exception) {
            $wait = $this->hostThrottle->record($endpoint->throttleKey, $exception->retryAfterSeconds);

            throw new CommentsThrottledException($endpoint->throttledMessage, $wait, $exception);
        } catch (FetchException $exception) {
            throw new CommentsUnavailableException($endpoint->unreachableMessage, previous: $exception);
        }
    }
}
