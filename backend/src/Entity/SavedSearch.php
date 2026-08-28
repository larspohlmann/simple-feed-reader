<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedSearchRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedSearchRepository::class)]
#[ORM\Table(name: 'saved_search')]
#[ORM\UniqueConstraint(name: 'uniq_saved_search_user_term_word', columns: ['user_id', 'term', 'whole_word'])]
class SavedSearch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $term;

    /** True when the search matches whole words only (a trailing space in the raw query). */
    #[ORM\Column(name: 'whole_word', options: ['default' => false])]
    private bool $wholeWord;

    /** Reserved for a future sidebar reorder; unused for ordering in v1. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Whether new matches feed this user's email digest (#636). */
    #[ORM\Column(name: 'include_in_digest', options: ['default' => false])]
    private bool $includeInDigest = false;

    public function __construct(User $user, string $term, bool $wholeWord)
    {
        $this->user = $user;
        $this->term = $term;
        $this->wholeWord = $wholeWord;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function isWholeWord(): bool
    {
        return $this->wholeWord;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isIncludeInDigest(): bool
    {
        return $this->includeInDigest;
    }

    public function setIncludeInDigest(bool $includeInDigest): void
    {
        $this->includeInDigest = $includeInDigest;
    }
}
