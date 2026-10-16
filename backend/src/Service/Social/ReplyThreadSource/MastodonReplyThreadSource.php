<?php

declare(strict_types=1);

namespace App\Service\Social\ReplyThreadSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\MastodonContextReplies;
use App\Service\Social\PostResolver\MastodonPostResolver;
use App\Service\Social\Support\MastodonStatusAddress;

final readonly class MastodonReplyThreadSource implements ReplyThreadSourceInterface
{
    public function __construct(
        private MastodonPostResolver $resolver,
        private MastodonAccountApi $mastodon,
        private MastodonApiClient $publicMastodon,
        private MastodonContextReplies $replies,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function asAccount(SocialAccount $account, string $address): CommentsAnswerModel
    {
        $post = $this->resolver->resolve($account, $address);
        $answer = $this->mastodon->askAboutPost($account, '/api/v1/statuses/' . $post->postId . '/context');
        $body = MastodonContextReplies::answerOf($post->postId, $answer->body);

        return null === $answer->header('Mastodon-Async-Refresh')
            ? CommentsAnswerModel::settled($body)
            : CommentsAnswerModel::stillGathering($body);
    }

    public function inPublic(string $address): CommentsAnswerModel
    {
        $status = MastodonStatusAddress::ofOrNull($address)
            ?? throw new SocialNetworkUnreachableException('The address names no status on a Mastodon instance.');
        $answer = MastodonApiClient::acceptedPublicStatus(
            $this->publicMastodon->answer(ApiRequestModel::get($status->contextApiUrl())),
            static fn (string $message): SocialNetworkUnreachableException
                => new SocialNetworkUnreachableException($message),
        );

        return CommentsAnswerModel::settled(MastodonContextReplies::answerOf($status->id, $answer->body));
    }

    public function publicThreadThrottledForSeconds(): int
    {
        return 0;
    }

    public function repliesIn(string $address, string $answer): array
    {
        return $this->replies->of($address, $answer);
    }
}
