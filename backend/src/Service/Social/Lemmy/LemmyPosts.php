<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\UrlOrigin;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Lemmy\Model\LemmyTargetModel;
use App\Service\Social\Lemmy\Support\LemmyView;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;

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
            $homeView = JsonNodeModel::fromValue($this->account->get($account, $path)->json());

            return LemmyView::fromPostView($homeView->node('post_view'));
        }
        $feedInstanceView = $this->lemmy->send(ApiRequestModel::get($post->instanceUrl . $path));
        $origin = JsonNodeModel::fromValue($feedInstanceView->json())->node('post_view')->node('post')->string('ap_id');

        return LemmyView::fromPostView($this->resolve($account, $origin)->node('post'));
    }

    /**
     * The post's id on the account's own instance: the post's own id there, with no request, and a lookup elsewhere.
     *
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function resolveHomePostId(SocialAccount $account, LemmyPostModel $post): int
    {
        return self::isHomeInstance($account, $post) ? $post->postId : $this->postOn($account, $post)->postId;
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
        return LemmyView::fromCommentView($this->resolve($account, $address)->node('comment'));
    }

    private function resolve(SocialAccount $account, ?string $address): JsonNodeModel
    {
        $absolute = AbsoluteHttpUrl::httpsOrNull($address);
        if (null === $absolute) {
            throw new SocialPostNotFoundException(self::NOT_ON_HOME);
        }
        $answer = $this->account->get($account, '/api/v3/resolve_object?q=' . rawurlencode($absolute));

        return JsonNodeModel::fromValue($answer->json());
    }

    private static function isHomeInstance(SocialAccount $account, LemmyPostModel $post): bool
    {
        $postInstance = UrlOrigin::canonical($post->instanceUrl);

        return null !== $postInstance && UrlOrigin::canonical($account->getServiceUrl()) === $postInstance;
    }
}
