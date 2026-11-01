<?php

declare(strict_types=1);

namespace App\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\MastodonVisibility;
use App\Service\Social\Support\PostCounts;

/** Asks the account's own instance for the post, so a post from any server gets the id that instance acts on. */
final readonly class MastodonPostResolver implements PostResolverInterface
{
    private const string SEARCH = '/api/v2/search?q=%s&resolve=true&type=statuses&limit=1';
    private const string STATUS_ID = '/^[A-Za-z0-9]{1,64}$/D';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $search = sprintf(self::SEARCH, rawurlencode($address));
        $status = JsonNodeModel::of($this->mastodon->askAboutPost($account, $search)->json())->first('statuses');
        $statusId = $status->string('id')
            ?? throw new SocialPostNotFoundException('The post is no longer on Mastodon.');
        if (1 !== preg_match(self::STATUS_ID, $statusId)) {
            throw new SocialNetworkUnreachableException('The Mastodon instance named a status id it cannot mean.');
        }

        $post = SocialPostModel::onMastodon($account, $statusId)->withCounts(PostCounts::ofMastodonStatus($status));

        return self::withViewer($post, $status, $statusId);
    }

    private static function withViewer(SocialPostModel $post, JsonNodeModel $status, string $statusId): SocialPostModel
    {
        $liked = $status->isTrue('favourited') ? $post->alreadyLikedAs($statusId) : $post;
        $reposted = $status->isTrue('reblogged') ? $liked->alreadyRepostedAs($statusId) : $liked;

        return MastodonVisibility::allowsReblog($status) ? $reposted : $reposted->notRepostable();
    }
}
