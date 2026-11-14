<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

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
        public ResponseContextModel $responseContext,
    ) {
    }

    public static function onBluesky(SocialAccount $account, string $uri, string $contentId): self
    {
        return self::fresh($account, $uri, $contentId);
    }

    public static function onMastodon(SocialAccount $account, string $statusId): self
    {
        return self::fresh($account, $statusId, null);
    }

    #[\NoDiscard]
    public function alreadyLikedAs(string $likeId): self
    {
        return $this->with(existingLikeId: $likeId);
    }

    #[\NoDiscard]
    public function alreadyRepostedAs(string $repostId): self
    {
        return $this->with(existingRepostId: $repostId);
    }

    #[\NoDiscard]
    public function notRepostable(): self
    {
        return $this->with(canRepost: false);
    }

    #[\NoDiscard]
    public function withCounts(?SocialCountsModel $counts): self
    {
        return $this->with(counts: $counts);
    }

    #[\NoDiscard]
    public function inResponseContext(ResponseContextModel $responseContext): self
    {
        return $this->with(responseContext: $responseContext);
    }

    private static function fresh(SocialAccount $account, string $postId, ?string $contentId): self
    {
        return new self(
            $account,
            $postId,
            $contentId,
            existingLikeId: null,
            existingRepostId: null,
            canRepost: true,
            counts: null,
            responseContext: new ResponseContextModel(),
        );
    }

    #[\NoDiscard]
    private function with(
        ?string $existingLikeId = null,
        ?string $existingRepostId = null,
        ?bool $canRepost = null,
        ?SocialCountsModel $counts = null,
        ?ResponseContextModel $responseContext = null,
    ): self {
        return clone($this, [
            'existingLikeId' => $existingLikeId ?? $this->existingLikeId,
            'existingRepostId' => $existingRepostId ?? $this->existingRepostId,
            'canRepost' => $canRepost ?? $this->canRepost,
            'counts' => $counts ?? $this->counts,
            'responseContext' => $responseContext ?? $this->responseContext,
        ]);
    }
}
