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

    private bool $alreadyLiked = false;

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
        $this->alreadyLiked = true;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $this->record('resolve ' . $address);
        $post = SocialPostModel::onMastodon($account, self::STATUS_ID)->withCounts($this->counts);

        return $this->alreadyLiked ? $post->alreadyLikedAs(self::STATUS_ID) : $post;
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
