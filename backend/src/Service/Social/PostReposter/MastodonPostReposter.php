<?php

declare(strict_types=1);

namespace App\Service\Social\PostReposter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\Model\SocialPostModel;

final readonly class MastodonPostReposter implements PostReposterInterface
{
    private const string STATUSES_SCOPE = 'write:statuses';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function repost(SocialPostModel $post): void
    {
        $this->actOnStatus($post->account, self::statusAction($post->postId, 'reblog'));
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        try {
            $this->actOnStatus($account, self::statusAction($repostId, 'unreblog'));
        } catch (SocialPostNotFoundException) {
        }
    }

    private function actOnStatus(SocialAccount $account, string $path): void
    {
        $this->mastodon->actOnPost($account, $path, self::STATUSES_SCOPE);
    }

    private static function statusAction(string $statusId, string $action): string
    {
        return '/api/v1/statuses/' . rawurlencode($statusId) . '/' . $action;
    }
}
