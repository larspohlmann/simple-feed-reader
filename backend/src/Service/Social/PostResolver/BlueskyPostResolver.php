<?php

declare(strict_types=1);

namespace App\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyLikeUri;

final readonly class BlueskyPostResolver implements PostResolverInterface
{
    private const string GET_POSTS = '/xrpc/app.bsky.feed.getPosts?uris=';

    public function __construct(private BlueskySessions $sessions)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $answer = BlueskySessions::accepted($this->sessions->askAs($account, self::GET_POSTS . rawurlencode($address)));
        $answerNode = JsonNodeModel::of($answer->json());
        if ([] === $answerNode->nodes('posts')) {
            throw new SocialPostNotFoundException('The post is no longer on Bluesky.');
        }
        $post = $answerNode->first('posts');
        $contentId = $post->string('cid')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered a post without a content id.');
        $resolved = SocialPostModel::onBluesky($account, $address, $contentId);
        $likeId = BlueskyLikeUri::orNull($post->node('viewer')->string('like'));

        return null === $likeId ? $resolved : $resolved->alreadyLikedAs($likeId);
    }
}
