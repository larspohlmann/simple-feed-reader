<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PendingPostEnrichmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A stored Bluesky post whose embed the AppView has not filled yet. */
#[ORM\Entity(repositoryClass: PendingPostEnrichmentRepository::class)]
#[ORM\Table(name: 'pending_post_enrichment')]
final class PendingPostEnrichment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(name: 'queued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $queuedAt;

    public function __construct(Entry $entry, \DateTimeImmutable $queuedAt)
    {
        $this->entry = $entry;
        $this->queuedAt = $queuedAt;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }
}
