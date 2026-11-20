<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LemmyPostRepository;
use Doctrine\ORM\Mapping as ORM;

/** What only a Lemmy post carries, once per item whichever feeds list it. Never mapped on the item, so no list loads it unasked. */
#[ORM\Entity(repositoryClass: LemmyPostRepository::class)]
#[ORM\Table(name: 'lemmy_post')]
final class LemmyPost
{
    public const int IDENTITY_MAX_LENGTH = 255;
    public const int URL_MAX_LENGTH = 2048;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: CanonicalItem::class)]
    #[ORM\JoinColumn(name: 'canonical_item_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private CanonicalItem $canonicalItem;

    #[ORM\Column(name: 'community_identity', length: self::IDENTITY_MAX_LENGTH, nullable: true)]
    private ?string $communityIdentity = null;

    #[ORM\Column(name: 'community_url', length: self::URL_MAX_LENGTH, nullable: true)]
    private ?string $communityUrl = null;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(CanonicalItem $canonicalItem)
    {
        $this->canonicalItem = $canonicalItem;
    }

    public function getCommunity(): ?PostCommunity
    {
        if ($this->communityIdentity === null) {
            return null;
        }

        return PostCommunity::fromIdentityAndUrl($this->communityIdentity, (string) $this->communityUrl);
    }

    public function getCanonicalItem(): CanonicalItem
    {
        return $this->canonicalItem;
    }
}
