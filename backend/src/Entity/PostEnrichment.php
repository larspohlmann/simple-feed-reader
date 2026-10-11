<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PostEnrichmentKind;
use App\Repository\PostEnrichmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A post whose embed or link cards are still to come. An AppView row is deleted once settled, or turns into a link-card
 * row while the post shares a link the AppView gave no card; a link-card row stays, marked done, as the fact that its
 * one attempt was made.
 */
#[ORM\Entity(repositoryClass: PostEnrichmentRepository::class)]
#[ORM\Table(name: 'post_enrichment')]
final class PostEnrichment
{
    public const string APPVIEW_GIVE_UP_AFTER = 'P3D';
    public const string CLAIM_LEASE = 'PT60S';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(length: 16, enumType: PostEnrichmentKind::class)]
    private PostEnrichmentKind $kind;

    #[ORM\Column(name: 'queued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $queuedAt;

    #[ORM\Column(name: 'claimed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'done_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    public function __construct(Entry $entry, PostEnrichmentKind $kind, \DateTimeImmutable $queuedAt)
    {
        $this->entry = $entry;
        $this->kind = $kind;
        $this->queuedAt = $queuedAt;
    }

    public function requireId(): int
    {
        return $this->id ?? throw new \LogicException('A post enrichment has no id before it is flushed.');
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getKind(): PostEnrichmentKind
    {
        return $this->kind;
    }

    public function awaitLinkCards(): void
    {
        $this->kind = PostEnrichmentKind::LinkCards;
    }
}
