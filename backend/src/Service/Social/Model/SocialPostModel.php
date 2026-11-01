<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;

/** A post as the acting account's server knows it, ready to be acted on (liked, reposted, replied to). */
final readonly class SocialPostModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param string      $postId           the Bluesky AT URI, or the status id on the account's own instance
     * @param string|null $contentId        the Bluesky record's CID, which a like or repost must name; null on Mastodon
     * @param string|null $existingLikeId   what unlikes the acting account's existing like: its Bluesky AT URI, or the
     *                                      status id on Mastodon
     * @param string|null $existingRepostId what undoes the acting account's repost, in the same form as a like
     */
    private function __construct(
        public SocialAccount $account,
        public string $postId,
        public ?string $contentId,
        public ?string $existingLikeId,
        public ?string $existingRepostId,
        public bool $canRepost,
        public ?SocialCountsModel $counts,
    ) {
    }

    public static function onBluesky(SocialAccount $account, string $uri, string $contentId): self
    {
        return new self($account, $uri, $contentId, null, null, true, null);
    }

    public static function onMastodon(SocialAccount $account, string $statusId): self
    {
        return new self($account, $statusId, null, null, null, true, null);
    }

    public function alreadyLikedAs(string $likeId): self
    {
        return $this->with(existingLikeId: $likeId);
    }

    public function alreadyRepostedAs(string $repostId): self
    {
        return $this->with(existingRepostId: $repostId);
    }

    public function notRepostable(): self
    {
        return $this->with(canRepost: false);
    }

    public function withCounts(?SocialCountsModel $counts): self
    {
        return new self(
            $this->account,
            $this->postId,
            $this->contentId,
            $this->existingLikeId,
            $this->existingRepostId,
            $this->canRepost,
            $counts,
        );
    }

    private function with(
        ?string $existingLikeId = null,
        ?string $existingRepostId = null,
        ?bool $canRepost = null,
    ): self {
        return new self(
            $this->account,
            $this->postId,
            $this->contentId,
            $existingLikeId ?? $this->existingLikeId,
            $existingRepostId ?? $this->existingRepostId,
            $canRepost ?? $this->canRepost,
            $this->counts,
        );
    }
}
