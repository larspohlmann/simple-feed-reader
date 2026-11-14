<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Bluesky\Support\BlueskyExternalCard;
use App\Service\Social\Social\BlueskyIdentityResolver;
use App\Service\Social\Social\BlueskySessions;
use App\Service\Social\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\PostPreviewModel;
use App\Service\Social\Social\Model\PostReferenceModel;
use App\Service\Social\Social\Model\ResponseContextModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\BlueskyAuthor;
use App\Service\Social\Social\Support\BlueskyRecordUri;
use App\Service\Social\Social\Support\PostCounts;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyPostResolver implements PostResolverInterface
{
    private const string GET_POSTS = '/xrpc/app.bsky.feed.getPosts?uris=';

    public function __construct(
        private BlueskySessions $sessions,
        private BlueskyIdentityResolver $identities,
    ) {
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $postUri = $this->byDid($address);
        $answer = BlueskySessions::accepted($this->sessions->askAs($account, self::GET_POSTS . rawurlencode($postUri)));
        $answerNode = JsonNodeModel::of($answer->json());
        if ([] === $answerNode->nodes('posts')) {
            throw new SocialPostNotFoundException('The post is no longer on Bluesky.');
        }
        $post = $answerNode->first('posts');
        $contentId = $post->string('cid')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered a post without a content id.');
        $resolved = SocialPostModel::onBluesky($account, $postUri, $contentId)
            ->withCounts(PostCounts::ofBlueskyPost($post));

        return self::withViewer($resolved, $post->node('viewer'))->inResponseContext(self::responseContextOf($post));
    }

    /**
     * getPosts finds no post whose AT URI names its author by handle, as a bsky.app address may.
     *
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkUnreachableException
     */
    private function byDid(string $address): string
    {
        $authority = AtPostUri::authorityOf($address);
        if (null === $authority || BlueskyActorKey::isDid($authority)) {
            return $address;
        }

        try {
            return AtPostUri::withAuthority($address, $this->identities->didOf($authority));
        } catch (SocialAccountUnresolvableException $exception) {
            throw new SocialPostNotFoundException($exception->getMessage(), previous: $exception);
        }
    }

    private static function responseContextOf(JsonNodeModel $post): ResponseContextModel
    {
        return new ResponseContextModel(
            authorIdentity: $post->node('author')->string('did'),
            threadRoot: self::referenceOrNull($post->node('record')->node('reply')->node('root')),
            canReply: !$post->node('viewer')->isTrue('replyDisabled'),
            canQuote: !$post->node('viewer')->isTrue('embeddingDisabled'),
            preview: self::previewOf($post),
        );
    }

    private static function previewOf(JsonNodeModel $post): PostPreviewModel
    {
        $author = $post->node('author');
        $handle = $author->string('handle');

        return new PostPreviewModel(
            BlueskyAuthor::name($author),
            null === $handle ? null : '@' . $handle,
            $post->node('record')->text('text') ?? '',
            BlueskyExternalCard::ofEmbed($post->node('embed')),
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
