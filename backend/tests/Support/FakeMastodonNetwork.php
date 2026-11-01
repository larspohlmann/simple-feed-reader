<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PostDeletion;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\ResponseContextModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use App\Service\Social\PostWriter\PostWriterInterface;
use App\Service\Social\PostReposter\PostReposterInterface;
use App\Service\Social\PostResolver\PostResolverInterface;

/** A Mastodon that resolves every address to status 110, records each call and can be told to fail. */
final class FakeMastodonNetwork implements
    PostResolverInterface,
    PostLikerInterface,
    PostReposterInterface,
    PostWriterInterface
{
    public const string STATUS_ID = '110';
    public const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/120';

    /** @var list<string> */
    public array $calls = [];

    private ?\Throwable $failure = null;

    private ?SocialCountsModel $counts = null;

    /** @var array<string, true> */
    private array $gonePostIds = [];

    private ?string $existingLikeId = null;

    private ?string $existingRepostId = null;

    private bool $canRepost = true;

    private ?\Closure $resolveHook = null;

    private ResponseContextModel $responseContext;

    public function __construct()
    {
        $this->responseContext = new ResponseContextModel();
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

    public function knowsGone(string $postId): void
    {
        $this->gonePostIds[$postId] = true;
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

    public function knowsTheResponseContext(ResponseContextModel $responseContext): void
    {
        $this->responseContext = $responseContext;
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
            ->inResponseContext($this->responseContext);
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

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        $this->record('reply ' . $target->postId . ' ' . $draft->text);

        return new PublishedPostModel(self::REPLY_URI, self::REPLY_URI);
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        $this->record('quote ' . $target->postId . ' ' . $draft->text);

        return new PublishedPostModel(self::REPLY_URI, self::REPLY_URI);
    }

    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel
    {
        $this->record('share ' . $url . ' ' . $draft->text);

        return new PublishedPostModel(self::REPLY_URI, self::REPLY_URI);
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $this->record('delete ' . $postId);

        return isset($this->gonePostIds[$postId]) ? PostDeletion::AlreadyGone : PostDeletion::Deleted;
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
