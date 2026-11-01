<?php

declare(strict_types=1);

namespace App\Service\Social\PostWriter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

final readonly class MastodonPostWriter implements PostWriterInterface
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

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, ['in_reply_to_id' => $target->postId]);
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, ['quoted_status_id' => $target->postId]);
    }

    public function share(SocialAccount $account, SharedLinkModel $link, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($account, $draft, []);
    }

    /** @param array<string, string> $attachment the id the status answers or quotes */
    private function published(SocialAccount $account, PostDraftModel $draft, array $attachment): PublishedPostModel
    {
        $request = ApiRequestModel::postJson(
            $account->getServiceUrl() . self::STATUSES,
            self::statusOf($draft, $attachment),
            null === $draft->idempotencyKey ? [] : ['Idempotency-Key' => $draft->idempotencyKey],
        );
        $status = JsonNodeModel::of($this->mastodon->writeAs($account, $request, self::STATUSES_SCOPE)->json());
        $address = AbsoluteHttpUrl::orNull($status->string('uri')) ?? AbsoluteHttpUrl::orNull($status->string('url'))
            ?? throw new SocialNetworkUnreachableException('Mastodon took the post but named no address for it.');

        return new PublishedPostModel($address, AbsoluteHttpUrl::orNull($status->string('url')));
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $url = $ownPost->account->getServiceUrl() . self::STATUSES . '/' . rawurlencode($ownPost->postId);
        try {
            $this->mastodon->writeAs($ownPost->account, ApiRequestModel::delete($url), self::STATUSES_SCOPE);
        } catch (SocialPostNotFoundException) {
        }
    }

    /**
     * @param array<string, string> $attachment
     *
     * @return array<string, string>
     */
    private static function statusOf(PostDraftModel $draft, array $attachment): array
    {
        return array_filter([
            'status' => $draft->text,
            ...$attachment,
            'visibility' => $draft->visibility,
            'spoiler_text' => $draft->contentWarning,
            'language' => $draft->language,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);
    }
}
