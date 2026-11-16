<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialNotificationKind;
use App\Repository\SocialNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialNotificationRepository::class)]
#[ORM\Table(name: 'social_notification')]
#[ORM\UniqueConstraint(name: 'uniq_social_notification_account_remote', columns: ['social_account_id', 'remote_id'])]
#[ORM\Index(name: 'idx_social_notification_group', columns: ['social_account_id', 'seen', 'kind', 'subject_group_key'])]
#[ORM\Index(name: 'idx_social_notification_created', columns: ['created_at'])]
final class SocialNotification
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly SocialAccount $socialAccount,
        #[ORM\Column(length: 512)]
        private readonly string $remoteId,
        #[ORM\Column(length: 16, enumType: SocialNotificationKind::class)]
        private readonly SocialNotificationKind $kind,
        #[ORM\Embedded(class: NotificationActor::class, columnPrefix: 'actor_')]
        private readonly NotificationActor $actor,
        #[ORM\Embedded(class: NotificationSubject::class, columnPrefix: 'subject_')]
        private readonly NotificationSubject $subject,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly \DateTimeImmutable $createdAt,
        #[ORM\Column]
        private bool $seen,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSocialAccount(): SocialAccount
    {
        return $this->socialAccount;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getKind(): SocialNotificationKind
    {
        return $this->kind;
    }

    public function getActor(): NotificationActor
    {
        return $this->actor;
    }

    public function getSubject(): NotificationSubject
    {
        return $this->subject;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isSeen(): bool
    {
        return $this->seen;
    }

    public function markSeen(): void
    {
        $this->seen = true;
    }
}
