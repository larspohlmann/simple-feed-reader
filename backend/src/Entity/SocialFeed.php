<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialFeedKind;
use App\Repository\SocialFeedRepository;
use Doctrine\ORM\Mapping as ORM;

/** Marks a Feed as one account's private timeline, list or custom feed (#1599). */
#[ORM\Entity(repositoryClass: SocialFeedRepository::class)]
#[ORM\Table(name: 'social_feed')]
#[ORM\Index(name: 'idx_social_feed_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_social_feed_feed', columns: ['feed_id'])]
#[ORM\UniqueConstraint(name: 'uniq_social_feed_remote', columns: ['social_account_id', 'kind', 'remote_id'])]
final class SocialFeed
{
    use PersistedId;

    /** Home has no remote id; stored as '' because SQL never finds two NULLs equal, which would let Home repeat. */
    private const string NO_REMOTE_ID = '';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialAccount $socialAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\Column(length: 16, enumType: SocialFeedKind::class)]
    private SocialFeedKind $kind;

    #[ORM\Column(length: 512)]
    private string $remoteId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $newestSeenId = null;

    public function __construct(SocialAccount $socialAccount, Feed $feed, SocialFeedKind $kind, ?string $remoteId)
    {
        $this->socialAccount = $socialAccount;
        $this->feed = $feed;
        $this->kind = $kind;
        $this->remoteId = self::storedRemoteId($remoteId);
    }

    public static function storedRemoteId(?string $remoteId): string
    {
        return $remoteId ?? self::NO_REMOTE_ID;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSocialAccount(): SocialAccount
    {
        return $this->socialAccount;
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    public function getKind(): SocialFeedKind
    {
        return $this->kind;
    }

    public function getRemoteId(): ?string
    {
        return self::NO_REMOTE_ID === $this->remoteId ? null : $this->remoteId;
    }

    public function getNewestSeenId(): ?string
    {
        return $this->newestSeenId;
    }

    public function recordNewestSeen(?string $statusId): void
    {
        if (null !== $statusId) {
            $this->newestSeenId = $statusId;
        }
    }
}
