<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use App\Service\Social\PostResolver\PostResolverInterface;

/** A Mastodon that resolves every address to status 110, records each call and can be told to fail. */
final class FakeMastodonNetwork implements PostResolverInterface, PostLikerInterface
{
    public const string STATUS_ID = '110';

    /** @var list<string> */
    public array $calls = [];

    private ?\Throwable $failure = null;

    private ?SocialCountsModel $counts = null;

    private ?string $existingLikeId = null;

    private ?\Closure $resolveHook = null;

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

    public function knowsTheLike(): void
    {
        $this->existingLikeId = self::STATUS_ID;
    }

    public function knowsTheLikeAs(string $likeId): void
    {
        $this->existingLikeId = $likeId;
    }

    public function onResolve(\Closure $hook): void
    {
        $this->resolveHook = $hook;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $this->record('resolve ' . $address);
        if (null !== $this->resolveHook) {
            ($this->resolveHook)();
        }
        $post = SocialPostModel::onMastodon($account, self::STATUS_ID)->withCounts($this->counts);

        return null === $this->existingLikeId ? $post : $post->alreadyLikedAs($this->existingLikeId);
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

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
