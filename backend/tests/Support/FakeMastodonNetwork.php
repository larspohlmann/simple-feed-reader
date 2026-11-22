<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\PostWriter\PostWriterInterface;
use App\Service\Social\Social\Model\PostContextModel;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\Model\SocialLikeResultModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\PostDeleter\PostDeleterInterface;
use App\Service\Social\Social\PostLiker\PostLikerInterface;
use App\Service\Social\Social\PostReposter\PostReposterInterface;
use App\Service\Social\Social\PostResolver\PostResolverInterface;

/** A Mastodon that resolves every address to status 110, records each call and can be told to fail. */
final class FakeMastodonNetwork implements
    PostResolverInterface,
    PostLikerInterface,
    PostReposterInterface,
    PostWriterInterface,
    PostDeleterInterface
{
    public const string STATUS_ID = '110';
    public const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/120';

    /** @var list<string> */
    public array $calls = [];

    /** @var list<?int> the account behind each resolve and unlike, in order */
    public array $actingAccountIds = [];

    private ?\Throwable $failure = null;

    private ?SocialCountsModel $counts = null;

    /** @var array<string, true> */
    private array $gonePostIds = [];

    private ?string $existingLikeId = null;

    private ?string $existingRepostId = null;

    private bool $canRepost = true;

    private ?\Closure $resolveHook = null;

    private PostContextModel $postContext;

    public function __construct()
    {
        $this->postContext = new PostContextModel();
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

    public function knowsThePostContext(PostContextModel $postContext): void
    {
        $this->postContext = $postContext;
    }

    public function onResolve(\Closure $hook): void
    {
        $this->resolveHook = $hook;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $this->actingAccountIds[] = $account->getId();
        $this->record('resolve ' . $address);
        if (null !== $this->resolveHook) {
            ($this->resolveHook)($account);
        }
        $post = SocialPostModel::onMastodon($account, self::STATUS_ID)
            ->withCounts($this->counts)
            ->withPostContext($this->postContext);
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
        $this->actingAccountIds[] = $account->getId();
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

    public function publish(SocialAccount $account, PublicationModel $publication): PublishedPostModel
    {
        $this->record(self::formatCall($publication) . ' ' . $publication->draft->text);

        return new PublishedPostModel(self::REPLY_URI, self::REPLY_URI);
    }

    private static function formatCall(PublicationModel $publication): string
    {
        $embed = $publication->embed;

        return match (true) {
            null !== $publication->inReplyTo => 'reply ' . $publication->inReplyTo->postId,
            $embed instanceof QuotedPostEmbedModel => 'quote ' . $embed->post->postId,
            $embed instanceof LinkEmbedModel => 'share ' . $embed->url,
            default => 'post',
        };
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
