<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PrivateFeedSyncReason;
use App\Enum\SocialFeedKind;
use App\Repository\PrivateFeedSyncChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One automatic change the hourly sync made to an account's lists and feeds (#1666). */
#[ORM\Entity(repositoryClass: PrivateFeedSyncChangeRepository::class)]
#[ORM\Table(name: 'private_feed_sync_change')]
#[ORM\Index(name: 'idx_private_feed_sync_change_account', columns: ['social_account_id', 'changed_at'])]
final class PrivateFeedSyncChange
{
    use PersistedId;

    private const int NAME_LENGTH = 512;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialAccount $socialAccount;

    #[ORM\Column(length: 16, enumType: SocialFeedKind::class)]
    private SocialFeedKind $kind;

    #[ORM\Column(length: 512)]
    private string $remoteId;

    #[ORM\Column(length: self::NAME_LENGTH)]
    private string $name;

    #[ORM\Column(length: 16, enumType: PrivateFeedSyncReason::class)]
    private PrivateFeedSyncReason $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(
        SocialAccount $socialAccount,
        SocialFeedKind $kind,
        string $remoteId,
        string $name,
        PrivateFeedSyncReason $reason,
        \DateTimeImmutable $changedAt,
    ) {
        $this->socialAccount = $socialAccount;
        $this->kind = $kind;
        $this->remoteId = $remoteId;
        $this->name = mb_substr($name, 0, self::NAME_LENGTH);
        $this->reason = $reason;
        $this->changedAt = $changedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSocialAccount(): SocialAccount
    {
        return $this->socialAccount;
    }

    public function getKind(): SocialFeedKind
    {
        return $this->kind;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getReason(): PrivateFeedSyncReason
    {
        return $this->reason;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
