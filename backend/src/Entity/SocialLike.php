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
#[ORM\Index(name: 'idx_social_like_canonical_item', columns: ['canonical_item_id'])]
#[ORM\Index(name: 'idx_social_like_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_social_like_user_canonical_item', columns: ['user_id', 'canonical_item_id'])]
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
    #[ORM\JoinColumn(name: 'canonical_item_id', nullable: false, onDelete: 'CASCADE')]
    private CanonicalItem $canonicalItem;

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
        CanonicalItem $canonicalItem,
        SocialAccount $socialAccount,
        string $remoteId,
        \DateTimeImmutable $likedAt,
    ) {
        $this->user = $user;
        $this->canonicalItem = $canonicalItem;
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

    public function getCanonicalItem(): CanonicalItem
    {
        return $this->canonicalItem;
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

    public function replaceWith(string $remoteId, \DateTimeImmutable $likedAt): void
    {
        $this->remoteId = $remoteId;
        $this->likedAt = $likedAt;
    }
}
