<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\MastodonApiClient;
use App\Service\Social\Mastodon\MastodonContextReplies;
use App\Service\Social\Mastodon\MastodonPostResolver;
use App\Service\Social\Mastodon\Support\MastodonStatusAddress;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\ReplyThreadSource\ReplyThreadSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonReplyThreadSource implements ReplyThreadSourceInterface
{
    public function __construct(
        private MastodonPostResolver $resolver,
        private MastodonAccountApi $mastodon,
        private MastodonApiClient $publicMastodon,
        private MastodonContextReplies $replies,
    ) {
    }

    public function asAccount(SocialAccount $account, string $address): CommentsAnswerModel
    {
        $post = $this->resolver->resolve($account, $address);
        $answer = $this->mastodon->askAboutPost($account, '/api/v1/statuses/' . $post->postId . '/context');
        $body = MastodonContextReplies::buildEnvelope($post->postId, $answer->body);

        return null === $answer->header('Mastodon-Async-Refresh')
            ? CommentsAnswerModel::settled($body)
            : CommentsAnswerModel::stillGathering($body);
    }

    public function inPublic(string $address): CommentsAnswerModel
    {
        $status = MastodonStatusAddress::parseAddress($address)
            ?? throw new SocialNetworkUnreachableException('The address names no status on a Mastodon instance.');
        $answer = MastodonApiClient::acceptedPublicStatus(
            $this->publicMastodon->answer(ApiRequestModel::get($status->contextApiUrl())),
            static fn (string $message): SocialNetworkUnreachableException
                => new SocialNetworkUnreachableException($message),
        );

        return CommentsAnswerModel::settled(MastodonContextReplies::buildEnvelope($status->id, $answer->body));
    }

    public function publicThreadThrottledForSeconds(): int
    {
        return 0;
    }

    public function parseReplies(string $address, string $answer): array
    {
        return $this->replies->parseReplies($address, $answer);
    }

    public function buildSelfReply(JsonNodeModel $post): EntryCommentModel
    {
        return $this->replies->buildSelfReply($post);
    }
}
