<?php

declare(strict_types=1);

namespace App\Service\Completion\Model;

use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Service\Bluesky\Model\JsonNodeModel;

/** What a network's own answer already says about a new entry's share, keyed by the entry's guid. */
final readonly class KnownSharesModel
{
    /**
     * @param array<string, JsonNodeModel>     $blueskyPosts
     * @param array<string, LinkCardContent>   $linkCards
     * @param array<string, QuotedPostContent> $quotedPosts
     * @param array<string, true>              $repliedGuids
     */
    private function __construct(
        private array $blueskyPosts,
        private array $linkCards,
        private array $quotedPosts,
        private array $repliedGuids,
    ) {
    }

    public static function none(): self
    {
        return new self([], [], [], []);
    }

    /** @param array<string, JsonNodeModel> $postsByGuid the post views a timeline answered with */
    public static function ofBlueskyPosts(array $postsByGuid): self
    {
        $replied = array_filter(
            $postsByGuid,
            static fn (JsonNodeModel $post): bool => ($post->int('replyCount') ?? 0) > 0,
        );

        return new self($postsByGuid, [], [], array_fill_keys(array_keys($replied), true));
    }

    /**
     * @param array<string, LinkCardContent>   $cardsByGuid  the preview cards the instance already built
     * @param array<string, QuotedPostContent> $quotesByGuid the statuses the instance says a post quotes
     * @param list<string>                     $repliedGuids the statuses the instance counts replies for
     */
    public static function ofMastodonShares(array $cardsByGuid, array $quotesByGuid, array $repliedGuids = []): self
    {
        return new self([], $cardsByGuid, $quotesByGuid, array_fill_keys($repliedGuids, true));
    }

    public function hasRepliesFor(string $guid): bool
    {
        return isset($this->repliedGuids[$guid]);
    }

    public function blueskyPostFor(string $guid): ?JsonNodeModel
    {
        return $this->blueskyPosts[$guid] ?? null;
    }

    public function linkCardFor(string $guid): ?LinkCardContent
    {
        return $this->linkCards[$guid] ?? null;
    }

    public function quotedPostFor(string $guid): ?QuotedPostContent
    {
        return $this->quotedPosts[$guid] ?? null;
    }
}
