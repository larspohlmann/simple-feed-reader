<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedSearchEntryMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry a saved search's terms match (#1116). A derived row: the sweep
 * writes it, every reader joins it, and it is safe to rebuild at any time.
 */
#[ORM\Entity(repositoryClass: SavedSearchEntryMembershipRepository::class)]
#[ORM\Table(name: 'saved_search_entry')]
#[ORM\Index(name: 'idx_saved_search_entry_entry', columns: ['entry_id'])]
class SavedSearchEntry
{
    // No `nullable: false` on the identifier join columns — Doctrine forces
    // identifier columns NOT NULL and deprecates stating it (see EntryState).
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: SavedSearch::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private SavedSearch $savedSearch;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(name: 'matched_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $matchedAt;

    public function __construct(SavedSearch $savedSearch, Entry $entry, \DateTimeImmutable $matchedAt)
    {
        $this->savedSearch = $savedSearch;
        $this->entry = $entry;
        $this->matchedAt = $matchedAt;
    }

    public function getSavedSearch(): SavedSearch
    {
        return $this->savedSearch;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getMatchedAt(): \DateTimeImmutable
    {
        return $this->matchedAt;
    }
}
