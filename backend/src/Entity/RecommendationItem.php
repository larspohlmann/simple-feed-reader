<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecommendationItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecommendationItemRepository::class)]
#[ORM\Table(name: 'recommendation_item')]
#[ORM\UniqueConstraint(name: 'uniq_recommendation_item_run_position', columns: ['recommendation_run_id', 'position'])]
final class RecommendationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RecommendationRun::class)]
    #[ORM\JoinColumn(name: 'recommendation_run_id', nullable: false, onDelete: 'CASCADE')]
    private RecommendationRun $run;

    // DB-level cascade, not ORM cascade: EntryPruner bulk-deletes entries via
    // DQL, which never runs ORM cascades (same reasoning as entry_state).
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    public function __construct(RecommendationRun $run, Entry $entry, int $position, string $reason)
    {
        $this->run = $run;
        $this->entry = $entry;
        $this->position = $position;
        $this->reason = $reason;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): RecommendationRun
    {
        return $this->run;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    // No setters — items are written once at run completion and never edited.
}
