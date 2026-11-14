<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\LemmyThreadReader\LemmyThreadReaderInterface;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Foundation\RateLimit\Exception\RateLimitedException;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Reads a Lemmy thread from the reader's own instance, where the votes on it are theirs. */
final readonly class LemmyAccountThreads implements LemmyThreadReaderInterface
{
    private const int RETRY_AFTER_UNNAMED_WAIT = 60;

    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private ActingAccountGuard $guard,
        private LemmyPosts $posts,
        private LemmyAccountApi $accountApi,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $socialRepliesLimiter,
    ) {
    }

    public function readerFor(User $user): ?SocialAccount
    {
        return $this->socialAccounts->findReadyActingOnNetwork($user, SocialNetwork::Lemmy);
    }

    public function threadAs(SocialAccount $account, LemmyPostModel $post): string
    {
        try {
            $this->rateLimitGuard->enforceForUser($this->socialRepliesLimiter, $account->getUser());


            return $this->guard->actAs($account, function () use ($account, $post): string {
                $localPostId = $this->posts->postOn($account, $post)->postId;

                return $this->accountApi->get(
                    $account,
                    '/api/v3/comment/list?post_id=' . $localPostId . '&sort=Hot&type_=All&max_depth=8&limit=50',
                )->body;
            });
        } catch (SocialNetworkThrottledException $exception) {
            throw new CommentsThrottledException(
                $exception->getMessage(),
                $exception->retryAfterSeconds ?? self::RETRY_AFTER_UNNAMED_WAIT,
                $exception,
            );
        } catch (
            RateLimitedException
            | SocialAccountNeedsReconnectException
            | SocialCredentialsRejectedException
            | SocialPostRefusedException
            | SocialPostNotFoundException
            | SocialNetworkUnreachableException $exception
        ) {
            throw new CommentsUnavailableException($exception->getMessage(), previous: $exception);
        }
    }
}
