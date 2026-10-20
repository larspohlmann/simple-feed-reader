<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SocialLikeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A user's like of a post at its origin (#1600), with the id the network gave it so it can be taken back. */
#[ORM\Entity(repositoryClass: SocialLikeRepository::class)]
#[ORM\Table(name: 'social_like')]
#[ORM\Index(name: 'idx_social_like_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_social_like_entry', columns: ['entry_id'])]
#[ORM\Index(name: 'idx_social_like_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_social_like_user_entry', columns: ['user_id', 'entry_id'])]
final class SocialLike
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialAccount $socialAccount;

    #[ORM\Column(length: 512)]
    private string $remoteId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $likedAt;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        User $user,
        Entry $entry,
        SocialAccount $socialAccount,
        string $remoteId,
        \DateTimeImmutable $likedAt,
    ) {
        $this->user = $user;
        $this->entry = $entry;
        $this->socialAccount = $socialAccount;
        $this->remoteId = $remoteId;
        $this->likedAt = $likedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getSocialAccount(): SocialAccount
    {
        return $this->socialAccount;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getLikedAt(): \DateTimeImmutable
    {
        return $this->likedAt;
    }

    public function replaceWith(SocialAccount $socialAccount, string $remoteId, \DateTimeImmutable $likedAt): void
    {
        $this->socialAccount = $socialAccount;
        $this->remoteId = $remoteId;
        $this->likedAt = $likedAt;
    }
}
