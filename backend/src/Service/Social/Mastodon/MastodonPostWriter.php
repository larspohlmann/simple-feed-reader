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
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\PostWriter\PostWriterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonPostWriter implements PostWriterInterface
{
    private const string STATUSES = '/api/v1/statuses';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, ['in_reply_to_id' => $target->postId]);
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, ['quoted_status_id' => $target->postId]);
    }

    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($account, $draft->withText(self::textCarrying($url, $draft->text)), []);
    }

    private static function textCarrying(string $url, string $text): string
    {
        return match (true) {
            '' === trim($text) => $url,
            str_contains($text, $url) => $text,
            default => $text . "\n\n" . $url,
        };
    }

    /** @param array<string, string> $attachment the id the status answers or quotes */
    private function published(SocialAccount $account, PostDraftModel $draft, array $attachment): PublishedPostModel
    {
        $request = ApiRequestModel::postJson(
            $account->getServiceUrl() . self::STATUSES,
            self::buildStatusPayload($draft, $attachment),
            null === $draft->idempotencyKey ? [] : ['Idempotency-Key' => $draft->idempotencyKey],
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

    /**
     * @param array<string, string> $attachment
     *
     * @return array<string, string>
     */
    private static function buildStatusPayload(PostDraftModel $draft, array $attachment): array
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
