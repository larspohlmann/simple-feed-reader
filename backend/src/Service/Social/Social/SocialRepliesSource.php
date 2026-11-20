<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Content\Comments\CommentsSource\CommentsSourceInterface;
use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\CommentsAudienceModel;
use App\Service\Content\Comments\Model\CommentsPageModel;
use App\Service\Content\Comments\Pass\CommentsRequest;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\ReplyThreadSource\ReplyThreadSourceInterface;
use App\Service\Social\Social\Support\CommentsFailures;
use App\Service\Social\Social\Support\OwnReplies;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[AsTaggedItem(priority: 10)]
final readonly class SocialRepliesSource implements CommentsSourceInterface
{
    public function __construct(
        private ActingAccountChooser $accounts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_reply_thread_source')]
        private ContainerInterface $threads,
        private ReplyThreadOrder $order,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $socialRepliesLimiter,
        private SocialAccountRepository $socialAccounts,
        private SocialPostAddresses $postAddresses,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== $this->postAddresses->findPost($entry);
    }

    public function buildAudience(User $user, Entry $entry): CommentsAudienceModel
    {
        $post = $this->requirePost($entry);
        $account = $this->findAccount($user, $entry, $post->network);
        $viewer = null === $account ? 'public' : 'account:' . $account->requireId();

        return new CommentsAudienceModel($post->network->value . ' ' . $post->address . ' ' . $viewer, $account);
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $post = $this->requirePost($request->entry);
        $account = $request->audience->actingAccount;
        $thread = $this->threadOn($post->network);
        if (null === $account) {
            self::refuseWhilePublicThreadIsThrottled($thread);
            $this->spendOneFetch($request->user);

            return self::fetchPublicThread($thread, $post->address);
        }
        $this->spendOneFetch($request->user);

        return $this->fetchThreadAsAccountOrPublic($thread, $account, $post->address);
    }

    public function read(CommentsRequest $request, string $answer): CommentsPageModel
    {
        $post = $this->requirePost($request->entry);
        $page = $this->order->buildPage($this->threadOn($post->network)->parseReplies($post->address, $answer));

        return new CommentsPageModel(
            OwnReplies::marked(
                $page->comments,
                $this->socialAccounts->findConnectedOnNetwork($request->user, $post->network),
            ),
            $page->omitted,
        );
    }


    private function fetchThreadAsAccountOrPublic(
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
            return self::fetchPublicThread($thread, $address);
        }
    }

    /**
     * @throws CommentsThrottledException
     * @throws CommentsUnavailableException
     */
    private static function fetchPublicThread(ReplyThreadSourceInterface $thread, string $address): CommentsAnswerModel
    {
        try {
            return $thread->inPublic($address);
        } catch (SocialNetworkThrottledException $exception) {
            throw CommentsFailures::throttled($exception);
        } catch (SocialPostNotFoundException | SocialNetworkUnreachableException $exception) {
            throw CommentsFailures::unavailable($exception);
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

    private function findAccount(User $user, Entry $entry, SocialNetwork $network): ?SocialAccount
    {
        try {
            return $this->accounts->requireAccount($user, $entry, $network);
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

    private function requirePost(Entry $entry): SocialPostAddressModel
    {
        return $this->postAddresses->findPost($entry)
            ?? throw new CommentsUnavailableException('The entry is not a post this reader can act on.');
    }
}
