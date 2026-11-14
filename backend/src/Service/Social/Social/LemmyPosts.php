<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\LemmyTargetModel;
use App\Service\Social\Social\Support\LemmyView;

/** Finds a post or comment on the account's own Lemmy instance, whichever instance the reader saw it on. */
final readonly class LemmyPosts
{
    private const string NOT_ON_HOME = 'The post has no https address to look up on your Lemmy instance.';

    public function __construct(private LemmyAccountApi $account, private LemmyApiClient $lemmy)
    {
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function postOn(SocialAccount $account, LemmyPostModel $post): LemmyTargetModel
    {
        $path = '/api/v3/post?id=' . $post->postId;
        if (self::isHomeInstance($account, $post)) {
            $homeView = JsonNodeModel::of($this->account->get($account, $path)->json());

            return LemmyView::ofPost($homeView->node('post_view'));
        }
        $feedInstanceView = $this->lemmy->send(ApiRequestModel::get($post->instanceUrl . $path));
        $origin = JsonNodeModel::of($feedInstanceView->json())->node('post_view')->node('post')->string('ap_id');

        return LemmyView::ofPost($this->resolve($account, $origin)->node('post'));
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function commentOn(SocialAccount $account, string $address): LemmyTargetModel
    {
        return LemmyView::ofComment($this->resolve($account, $address)->node('comment'));
    }

    private function resolve(SocialAccount $account, ?string $address): JsonNodeModel
    {
        $absolute = AbsoluteHttpUrl::orNull($address);
        if (null === $absolute || !str_starts_with(mb_strtolower($absolute), 'https://')) {
            throw new SocialPostNotFoundException(self::NOT_ON_HOME);
        }
        $answer = $this->account->get($account, '/api/v3/resolve_object?q=' . rawurlencode($absolute));

        return JsonNodeModel::of($answer->json());
    }

    private static function isHomeInstance(SocialAccount $account, LemmyPostModel $post): bool
    {
        $host = parse_url($post->instanceUrl, \PHP_URL_HOST);

        return \is_string($host) && mb_strtolower($host) === mb_strtolower($account->getServiceHost() ?? '');
    }
}
