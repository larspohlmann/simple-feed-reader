<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareEnrichmentKind;
use App\Repository\ShareEnrichmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry whose embed or link cards are still to come. An AppView row is deleted once settled, or turns into a
 * link-card row while the post shares a link the AppView gave no card; a link-card row stays, marked done, as the
 * fact that its one attempt was made.
 */
#[ORM\Entity(repositoryClass: ShareEnrichmentRepository::class)]
#[ORM\Table(name: 'share_enrichment')]
#[ORM\Index(name: 'idx_share_enrichment_claimable', columns: ['kind', 'done_at', 'queued_at'])]
final class ShareEnrichment
{
    public const string APPVIEW_GIVE_UP_AFTER = 'P3D';
    public const int CLAIM_LEASE_SECONDS = 60;
    public const string CLAIM_LEASE = 'PT' . self::CLAIM_LEASE_SECONDS . 'S';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(length: 16, enumType: ShareEnrichmentKind::class)]
    private ShareEnrichmentKind $kind;

    #[ORM\Column(name: 'queued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $queuedAt;

    #[ORM\Column(name: 'claimed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'done_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    public function __construct(Entry $entry, ShareEnrichmentKind $kind, \DateTimeImmutable $queuedAt)
    {
        $this->entry = $entry;
        $this->kind = $kind;
        $this->queuedAt = $queuedAt;
    }

    public function requireId(): int
    {
        return $this->id ?? throw new \LogicException('A share enrichment has no id before it is flushed.');
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getKind(): ShareEnrichmentKind
    {
        return $this->kind;
    }

    public function awaitLinkCards(): void
    {
        $this->kind = ShareEnrichmentKind::LinkCards;
    }

    public function awaitSelfThread(): void
    {
        $this->kind = ShareEnrichmentKind::SelfThread;
    }
}
