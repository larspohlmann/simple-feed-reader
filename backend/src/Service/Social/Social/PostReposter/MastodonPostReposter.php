<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostReposter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\MastodonAccountApi;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\MastodonStatusAction;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonPostReposter implements PostReposterInterface
{
    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public function repost(SocialPostModel $post): void
    {
        $this->actOnStatus($post->account, MastodonStatusAction::path($post->postId, 'reblog'));
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        try {
            $this->actOnStatus($account, MastodonStatusAction::path($repostId, 'unreblog'));
        } catch (SocialPostNotFoundException) {
        }
    }

    private function actOnStatus(SocialAccount $account, string $path): void
    {
        $this->mastodon->actOnPost($account, $path, MastodonAccountApi::STATUSES_SCOPE);
    }
}
