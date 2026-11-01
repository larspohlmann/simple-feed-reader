<?php

declare(strict_types=1);

namespace App\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\PostReferenceModel;
use App\Service\Social\Model\ReplyContextModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;
use App\Service\Social\Support\PostCounts;

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
        $resolved = SocialPostModel::onBluesky($account, $address, $contentId)
            ->withCounts(PostCounts::ofBlueskyPost($post));

        return self::withViewer($resolved, $post->node('viewer'))->inReplyContext(self::replyContextOf($post));
    }

    private static function replyContextOf(JsonNodeModel $post): ReplyContextModel
    {
        return new ReplyContextModel(
            authorIdentity: $post->node('author')->string('did'),
            threadRoot: self::referenceOrNull($post->node('record')->node('reply')->node('root')),
            canReply: !$post->node('viewer')->isTrue('replyDisabled'),
        );
    }

    private static function referenceOrNull(JsonNodeModel $reference): ?PostReferenceModel
    {
        $uri = $reference->string('uri');
        $contentId = $reference->string('cid');
        if (null === $uri || null === $contentId || !AtPostUri::matches($uri)) {
            return null;
        }

        return new PostReferenceModel($uri, $contentId);
    }

    private static function withViewer(SocialPostModel $resolved, JsonNodeModel $viewer): SocialPostModel
    {
        $likeId = BlueskyRecordUri::likeOrNull($viewer->string('like'));
        $repostId = BlueskyRecordUri::repostOrNull($viewer->string('repost'));
        $liked = null === $likeId ? $resolved : $resolved->alreadyLikedAs($likeId);

        return null === $repostId ? $liked : $liked->alreadyRepostedAs($repostId);
    }
}
