<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;

/** A post as the acting account's server knows it, ready to be acted on (liked now, replied to later). */
final readonly class SocialPostModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param string      $postId         the Bluesky AT URI, or the status id on the account's own Mastodon instance
     * @param string|null $contentId      the Bluesky record's CID, which a like or a reply must name; null on Mastodon
     * @param string|null $existingLikeId the AT URI of the acting account's existing like, Bluesky only
     */
    private function __construct(
        public SocialAccount $account,
        public string $postId,
        public ?string $contentId,
        public ?string $existingLikeId,
    ) {
    }

    public static function onBluesky(SocialAccount $account, string $uri, string $contentId): self
    {
        return new self($account, $uri, $contentId, null);
    }

    public static function onMastodon(SocialAccount $account, string $statusId): self
    {
        return new self($account, $statusId, null, null);
    }

    public function alreadyLikedAs(string $likeId): self
    {
        return new self($this->account, $this->postId, $this->contentId, $likeId);
    }
}
