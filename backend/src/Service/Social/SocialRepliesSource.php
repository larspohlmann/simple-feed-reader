<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Comments\CommentsSource\CommentsSourceInterface;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsAudienceModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\ReplyThreadSource\ReplyThreadSourceInterface;
use App\Service\Social\Support\OwnReplies;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[AsTaggedItem(priority: 10)]
final readonly class SocialRepliesSource implements CommentsSourceInterface
{
    private const int RETRY_AFTER_UNNAMED_WAIT = 60;

    public function __construct(
        private ActingAccountChooser $accounts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_reply_thread_source', defaultIndexMethod: 'network')]
        private ContainerInterface $threads,
        private ReplyThreadOrder $order,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialRepliesLimiter,
        private SocialAccountRepository $socialAccounts,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== SocialPostAddress::of($entry);
    }

    public function audienceOf(User $user, Entry $entry): CommentsAudienceModel
    {
        $post = self::postOf($entry);
        $account = $this->accountOrNull($user, $entry, $post->network);
        $viewer = null === $account ? 'public' : 'account:' . $account->requireId();

        return new CommentsAudienceModel($post->network->value . ' ' . $post->address . ' ' . $viewer, $account);
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $post = self::postOf($request->entry);
        $account = $request->audience->actingAccount;
        $thread = $this->threadOn($post->network);
        if (null === $account) {
            self::refuseWhilePublicThreadIsThrottled($thread);
            $this->spendOneFetch($request->user);

            return self::publicThreadOf($thread, $post->address);
        }
        $this->spendOneFetch($request->user);

        return $this->accountOrPublicThreadOf($thread, $account, $post->address);
    }

    public function read(CommentsRequest $request, string $answer): CommentsPageModel
    {
        $post = self::postOf($request->entry);
        $page = $this->order->pageOf($this->threadOn($post->network)->repliesIn($post->address, $answer));

        return new CommentsPageModel(
            OwnReplies::marked(
                $page->comments,
                $this->socialAccounts->findConnectedOnNetwork($request->user, $post->network),
            ),
            $page->omitted,
        );
    }


    private function accountOrPublicThreadOf(
        ReplyThreadSourceInterface $thread,
        SocialAccount $account,
        string $address,
    ): CommentsAnswerModel {
        try {
            return $this->guard->actAs(
                $account,
                static fn (): CommentsAnswerModel => $thread->asAccount($account, $address),
            );
        } catch (
            SocialAccountNeedsReconnectException
            | SocialCredentialsRejectedException
            | SocialTokenScopeMissingException
            | SocialPostRefusedException
            | SocialPostNotFoundException
            | SocialNetworkUnreachableException
        ) {
            return self::publicThreadOf($thread, $address);
        }
    }

    /**
     * @throws CommentsThrottledException
     * @throws CommentsUnavailableException
     */
    private static function publicThreadOf(ReplyThreadSourceInterface $thread, string $address): CommentsAnswerModel
    {
        try {
            return $thread->inPublic($address);
        } catch (SocialNetworkThrottledException $exception) {
            throw new CommentsThrottledException(
                $exception->getMessage(),
                $exception->retryAfterSeconds ?? self::RETRY_AFTER_UNNAMED_WAIT,
                $exception,
            );
        } catch (SocialPostNotFoundException | SocialNetworkUnreachableException $exception) {
            throw new CommentsUnavailableException($exception->getMessage(), previous: $exception);
        }
    }

    /** @throws CommentsThrottledException */
    private static function refuseWhilePublicThreadIsThrottled(ReplyThreadSourceInterface $thread): void
    {
        $wait = $thread->publicThreadThrottledForSeconds();
        if ($wait > 0) {
            throw new CommentsThrottledException('The network asked for fewer requests.', $wait);
        }
    }

    private function spendOneFetch(User $user): void
    {
        $this->rateLimitGuard->enforceForUser($this->socialRepliesLimiter, $user);
    }

    private function accountOrNull(User $user, Entry $entry, SocialNetwork $network): ?SocialAccount
    {
        try {
            return $this->accounts->accountFor($user, $entry, $network);
        } catch (NoSocialAccountForNetworkException) {
            return null;
        }
    }

    private function threadOn(SocialNetwork $network): ReplyThreadSourceInterface
    {
        $thread = $this->threads->get($network->value);
        \assert($thread instanceof ReplyThreadSourceInterface);

        return $thread;
    }

    private static function postOf(Entry $entry): SocialPostAddressModel
    {
        return SocialPostAddress::of($entry)
            ?? throw new CommentsUnavailableException('The entry is not a Bluesky or Mastodon post.');
    }
}
