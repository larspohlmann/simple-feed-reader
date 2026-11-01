<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PublishedReplyModel;
use App\Service\Social\Model\ReplyContextModel;
use App\Service\Social\Model\ReplyDraftModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use App\Service\Social\PostReplier\PostReplierInterface;
use App\Service\Social\PostReposter\PostReposterInterface;
use App\Service\Social\PostResolver\PostResolverInterface;

/** A Mastodon that resolves every address to status 110, records each call and can be told to fail. */
final class FakeMastodonNetwork implements
    PostResolverInterface,
    PostLikerInterface,
    PostReposterInterface,
    PostReplierInterface
{
    public const string STATUS_ID = '110';
    public const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/120';

    /** @var list<string> */
    public array $calls = [];

    private ?\Throwable $failure = null;

    private ?SocialCountsModel $counts = null;

    private ?string $existingLikeId = null;

    private ?string $existingRepostId = null;

    private bool $canRepost = true;

    private ?\Closure $resolveHook = null;

    private ReplyContextModel $replyContext;

    public function __construct()
    {
        $this->replyContext = new ReplyContextModel();
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function knowsCounts(SocialCountsModel $counts): void
    {
        $this->counts = $counts;
    }

    public function knowsTheLike(string $likeId = self::STATUS_ID): void
    {
        $this->existingLikeId = $likeId;
    }

    public function knowsTheRepost(string $repostId = self::STATUS_ID): void
    {
        $this->existingRepostId = $repostId;
    }

    public function forbidsReposting(): void
    {
        $this->canRepost = false;
    }

    public function knowsTheReplyContext(ReplyContextModel $replyContext): void
    {
        $this->replyContext = $replyContext;
    }

    public function onResolve(\Closure $hook): void
    {
        $this->resolveHook = $hook;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $this->record('resolve ' . $address);
        if (null !== $this->resolveHook) {
            ($this->resolveHook)($account);
        }
        $post = SocialPostModel::onMastodon($account, self::STATUS_ID)
            ->withCounts($this->counts)
            ->inReplyContext($this->replyContext);
        $liked = null === $this->existingLikeId ? $post : $post->alreadyLikedAs($this->existingLikeId);
        $reposted = null === $this->existingRepostId ? $liked : $liked->alreadyRepostedAs($this->existingRepostId);

        return $this->canRepost ? $reposted : $reposted->notRepostable();
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $this->record('like ' . $post->postId);

        return new SocialLikeResultModel($post->postId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $this->record('unlike ' . $likeId);
    }

    public function repost(SocialPostModel $post): void
    {
        $this->record('repost ' . $post->postId);
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        $this->record('unrepost ' . $repostId);
    }

    public function publish(SocialPostModel $target, ReplyDraftModel $draft): PublishedReplyModel
    {
        $this->record('reply ' . $target->postId . ' ' . $draft->text);

        return new PublishedReplyModel(self::REPLY_URI, self::REPLY_URI);
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $this->record('delete ' . $ownPost->postId);
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
