<?php

declare(strict_types=1);

namespace App\Service\Social\PostReplier;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\Model\PublishedReplyModel;
use App\Service\Social\Model\ReplyDraftModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

final readonly class MastodonPostReplier implements PostReplierInterface
{
    private const string STATUSES = '/api/v1/statuses';
    private const string STATUSES_SCOPE = 'write:statuses';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function publish(SocialPostModel $target, ReplyDraftModel $draft): PublishedReplyModel
    {
        $request = ApiRequestModel::postJson(
            $target->account->getServiceUrl() . self::STATUSES,
            self::statusOf($target, $draft),
            null === $draft->idempotencyKey ? [] : ['Idempotency-Key' => $draft->idempotencyKey],
        );
        $status = JsonNodeModel::of($this->mastodon->writeAs($target->account, $request, self::STATUSES_SCOPE)->json());
        $address = AbsoluteHttpUrl::orNull($status->string('uri')) ?? AbsoluteHttpUrl::orNull($status->string('url'))
            ?? throw new SocialNetworkUnreachableException('Mastodon took the reply but named no address for it.');

        return new PublishedReplyModel($address, AbsoluteHttpUrl::orNull($status->string('url')));
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $url = $ownPost->account->getServiceUrl() . self::STATUSES . '/' . rawurlencode($ownPost->postId);
        try {
            $this->mastodon->writeAs($ownPost->account, ApiRequestModel::delete($url), self::STATUSES_SCOPE);
        } catch (SocialPostNotFoundException) {
        }
    }

    /** @return array<string, string> */
    private static function statusOf(SocialPostModel $target, ReplyDraftModel $draft): array
    {
        return array_filter([
            'status' => $draft->text,
            'in_reply_to_id' => $target->postId,
            'visibility' => $draft->visibility,
            'spoiler_text' => $draft->contentWarning,
            'language' => $draft->language,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);
    }
}
