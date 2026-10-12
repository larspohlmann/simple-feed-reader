<?php

declare(strict_types=1);

namespace App\Service\Completion\Model;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;

/** What a network's own answer already says about a new entry's share, keyed by the entry's guid. */
final readonly class KnownSharesModel
{
    /**
     * @param array<string, JsonNodeModel>   $blueskyPosts
     * @param array<string, LinkCardContent> $linkCards
     */
    private function __construct(
        private array $blueskyPosts,
        private array $linkCards,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** @param array<string, JsonNodeModel> $postsByGuid the post views a timeline answered with */
    public static function ofBlueskyPosts(array $postsByGuid): self
    {
        return new self($postsByGuid, []);
    }

    /** @param array<string, LinkCardContent> $cardsByGuid the preview cards the instance already built */
    public static function ofLinkCards(array $cardsByGuid): self
    {
        return new self([], $cardsByGuid);
    }

    public function blueskyPostFor(string $guid): ?JsonNodeModel
    {
        return $this->blueskyPosts[$guid] ?? null;
    }

    public function linkCardFor(string $guid): ?LinkCardContent
    {
        return $this->linkCards[$guid] ?? null;
    }
}
