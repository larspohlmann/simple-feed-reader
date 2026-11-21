<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CanonicalItemKind;
use App\Repository\CanonicalItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The one row behind every feed's copy of an item; its counts are the item's, whichever copy fetched them last. */
#[ORM\Entity(repositoryClass: CanonicalItemRepository::class)]
#[ORM\Table(name: 'canonical_item')]
#[ORM\UniqueConstraint(name: 'uniq_canonical_item_kind_key', columns: ['kind', 'key_hash'])]
#[ORM\Index(name: 'idx_canonical_item_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_canonical_item_cross_post_hash', columns: ['cross_post_hash'])]
final class CanonicalItem implements DiscussionCountStoreInterface
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: CanonicalItemKind::class)]
    private CanonicalItemKind $kind;

    #[ORM\Column(name: 'canonical_key', length: 2048)]
    private string $canonicalKey;

    #[ORM\Column(name: 'key_hash', length: 64)]
    private string $keyHash;

    #[ORM\Column(name: 'cross_post_hash', length: 64, nullable: true)]
    private ?string $crossPostHash = null;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    #[ORM\Column(name: 'comment_count', nullable: true)]
    private ?int $commentCount = null;

    #[ORM\Column(name: 'like_count', nullable: true)]
    private ?int $likeCount = null;

    #[ORM\Column(name: 'self_reply_count', type: Types::SMALLINT, options: ['default' => 0])]
    private int $selfReplyCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CanonicalItemKind $kind, string $canonicalKey, \DateTimeImmutable $createdAt)
    {
        $this->kind = $kind;
        $this->canonicalKey = $canonicalKey;
        $this->keyHash = self::hashKey($canonicalKey);
        $this->createdAt = $createdAt;
    }

    public static function hashKey(string $canonicalKey): string
    {
        return hash('sha256', $canonicalKey);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): CanonicalItemKind
    {
        return $this->kind;
    }

    public function getCanonicalKey(): string
    {
        return $this->canonicalKey;
    }

    public function getCrossPostHash(): ?string
    {
        return $this->crossPostHash;
    }

    public function recordCrossPostHash(string $hash): void
    {
        $this->crossPostHash ??= $hash;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function recordScore(?int $score): void
    {
        $this->score = $score ?? $this->score;
    }

    public function recordCounts(DiscussionCounts $counts): void
    {
        $this->recordScore($counts->score);
        $this->recordCommentCount($counts->commentCount);
        $this->likeCount = $counts->likeCount ?? $this->likeCount;
    }

    public function getCommentCount(): ?int
    {
        return $this->commentCount;
    }

    public function recordCommentCount(?int $commentCount): void
    {
        $this->commentCount = $commentCount ?? $this->commentCount;
    }

    public function getLikeCount(): ?int
    {
        return $this->likeCount;
    }

    public function getSelfReplyCount(): int
    {
        return $this->selfReplyCount;
    }

    public function recordSelfReplyCount(int $selfReplyCount): void
    {
        $this->selfReplyCount = max($this->selfReplyCount, $selfReplyCount);
    }
}
