<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedSearchRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedSearchRepository::class)]
#[ORM\Table(name: 'saved_search')]
#[ORM\UniqueConstraint(
    name: 'uniq_saved_search_user_term_mode',
    columns: ['user_id', 'term', 'whole_word', 'phrase'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_saved_search_user_slug',
    columns: ['user_id', 'slug'],
)]
class SavedSearch
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $term;

    /**
     * The stable URL slug, "<id>-<slug of term>". Null only in the instant
     * between persisting the row (which assigns the id) and setting the slug
     * from it; every stored row has one. Immutable once set — the term never
     * changes, so the slug never does.
     */
    #[ORM\Column(length: 130, nullable: true)]
    private ?string $slug = null;

    /** True when the search matches whole words only (a trailing space in the raw query). */
    #[ORM\Column(name: 'whole_word', options: ['default' => false])]
    private bool $wholeWord;

    /** True when the search matches one exact phrase (the raw query wrapped in double quotes). */
    #[ORM\Column(name: 'phrase', options: ['default' => false])]
    private bool $phrase;

    /** Reserved for a future sidebar reorder; unused for ordering in v1. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Whether new matches feed this user's email digest (#636). */
    #[ORM\Column(name: 'include_in_digest', options: ['default' => false])]
    private bool $includeInDigest = false;

    /**
     * The membership sweep's high-water mark: every entry with an id up to
     * this one has been checked against this search's terms (#1116).
     */
    #[ORM\Column(name: 'matched_up_to_entry_id', options: ['default' => 0])]
    private int $matchedUpToEntryId = 0;

    public function __construct(User $user, string $term, bool $wholeWord, bool $phrase = false)
    {
        $this->user = $user;
        $this->term = $term;
        $this->wholeWord = $wholeWord;
        $this->phrase = $phrase;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function isWholeWord(): bool
    {
        return $this->wholeWord;
    }

    public function isPhrase(): bool
    {
        return $this->phrase;
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

    public function matchedUpToEntryId(): int
    {
        return $this->matchedUpToEntryId;
    }

    public function advanceMatchedUpTo(int $entryId): void
    {
        $this->matchedUpToEntryId = max($this->matchedUpToEntryId, $entryId);
    }
}
