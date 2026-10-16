<?php

declare(strict_types=1);

namespace App\Service\Social\ReplyThreadSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\MastodonContextReplies;
use App\Service\Social\PostResolver\MastodonPostResolver;
use App\Service\Social\Support\MastodonStatusAddress;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonReplyThreadSource implements ReplyThreadSourceInterface
{
    private const array CLOSED_TO_THE_PUBLIC = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

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

        return null === $answer->header('Mastodon-Async-Refresh')
            ? CommentsAnswerModel::settled($answer->body)
            : CommentsAnswerModel::stillGathering($answer->body);
    }

    public function inPublic(string $address): CommentsAnswerModel
    {
        $contextUrl = MastodonStatusAddress::contextApiUrlOrNull($address)
            ?? throw new SocialNetworkUnreachableException('The address names no status on a Mastodon instance.');
        $answer = self::accepted($this->publicMastodon->answer(ApiRequestModel::get($contextUrl)));

        return CommentsAnswerModel::settled($answer->body);
    }

    public function repliesIn(string $address, string $answer): array
    {
        return $this->replies->of($address, $answer);
    }

    private static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialPostNotFoundException('The post is no longer on its Mastodon instance.');
        }
        if (\in_array($answer->status, self::CLOSED_TO_THE_PUBLIC, true)) {
            throw new SocialNetworkUnreachableException('The Mastodon instance shows its statuses to accounts only.');
        }

        return MastodonApiClient::accepted($answer);
    }
}
