<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SocialNotificationPollRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialNotificationPollRepository::class)]
#[ORM\Table(name: 'social_notification_poll')]
final class SocialNotificationPoll
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $scopeMissing = false;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private readonly SocialAccount $socialAccount,
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

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function isScopeMissing(): bool
    {
        return $this->scopeMissing;
    }

    public function recordChecked(\DateTimeImmutable $at): void
    {
        $this->checkedAt = $at;
        $this->scopeMissing = false;
    }

    public function recordScopeMissing(\DateTimeImmutable $at): void
    {
        $this->checkedAt = $at;
        $this->scopeMissing = true;
    }
}
