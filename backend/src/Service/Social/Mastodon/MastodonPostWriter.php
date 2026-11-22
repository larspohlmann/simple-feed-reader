<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\Support\MastodonStatusAction;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\PostEmbedModelInterface;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\PostWriter\PostWriterInterface;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\PostDeleter\PostDeleterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonPostWriter implements PostWriterInterface, PostDeleterInterface
{
    private const string STATUSES = '/api/v1/statuses';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public function publish(SocialAccount $account, PublicationModel $publication): PublishedPostModel
    {
        $idempotencyKey = $publication->draft->idempotencyKey;
        $request = ApiRequestModel::postJson(
            $account->getServiceUrl() . self::STATUSES,
            self::buildStatusPayload($publication),
            null === $idempotencyKey ? [] : ['Idempotency-Key' => $idempotencyKey],
        );
        $answer = $this->mastodon->writeAs($account, $request, MastodonAccountApi::STATUSES_SCOPE);
        $status = JsonNodeModel::fromValue($answer->json());
        $address = AbsoluteHttpUrl::keepIfAbsolute($status->string('uri'))
            ?? AbsoluteHttpUrl::keepIfAbsolute($status->string('url'))
            ?? throw new SocialNetworkUnreachableException('Mastodon took the post but named no address for it.');

        return new PublishedPostModel($address, AbsoluteHttpUrl::keepIfAbsolute($status->string('url')));
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $url = $account->getServiceUrl() . MastodonStatusAction::statusPath($postId);
        try {
            $this->mastodon->writeAs($account, ApiRequestModel::delete($url), MastodonAccountApi::STATUSES_SCOPE);
        } catch (SocialPostNotFoundException) {
            return PostDeletion::AlreadyGone;
        }

        return PostDeletion::Deleted;
    }

    /** @return array<string, string> */
    private static function buildStatusPayload(PublicationModel $publication): array
    {
        $draft = $publication->draft;

        return array_filter([
            'status' => self::buildStatusText($publication),
            'in_reply_to_id' => $publication->inReplyTo?->postId,
            'quoted_status_id' => self::readQuotedStatusId($publication->embed),
            'visibility' => $draft->visibility,
            'spoiler_text' => $draft->contentWarning,
            'language' => $draft->language,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);
    }

    private static function buildStatusText(PublicationModel $publication): string
    {
        $embed = $publication->embed;

        return $embed instanceof LinkEmbedModel
            ? self::textCarrying($embed->url, $publication->draft->text)
            : $publication->draft->text;
    }

    private static function readQuotedStatusId(?PostEmbedModelInterface $embed): ?string
    {
        return $embed instanceof QuotedPostEmbedModel ? $embed->post->postId : null;
    }

    private static function textCarrying(string $url, string $text): string
    {
        return match (true) {
            '' === trim($text) => $url,
            str_contains($text, $url) => $text,
            default => $text . "\n\n" . $url,
        };
    }
}
