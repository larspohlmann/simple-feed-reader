<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialFeedKind;
use App\Repository\KnownSocialCollectionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A list or custom feed the sync has seen for an account; one the account no longer subscribes is one the user dropped (#1666). */
#[ORM\Entity(repositoryClass: KnownSocialCollectionRepository::class)]
#[ORM\Table(name: 'known_social_collection')]
#[ORM\Index(name: 'idx_known_social_collection_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_known_social_collection', columns: ['social_account_id', 'kind', 'remote_id'])]
final class KnownSocialCollection
{
    use PersistedId;

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

    public function __construct(SocialAccount $socialAccount, SocialFeedKind $kind, string $remoteId)
    {
        $this->socialAccount = $socialAccount;
        $this->kind = $kind;
        $this->remoteId = $remoteId;
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
}
