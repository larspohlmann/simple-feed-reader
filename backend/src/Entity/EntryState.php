<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryStateRepository::class)]
#[ORM\Table(name: 'entry_state')]
final class EntryState
{
    // No `nullable: false` on these identifier join columns: Doctrine forces them NOT NULL and deprecates saying so.
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column]
    private bool $isHidden = false;

    #[ORM\Column]
    private bool $isFavorite = false;

    #[ORM\Column]
    private bool $isKept = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hiddenAt = null;

    #[ORM\Column]
    private bool $isViewed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $viewedAt = null;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, Entry $entry)
    {
        $this->user = $user;
        $this->entry = $entry;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function isHidden(): bool
    {
        return $this->isHidden;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function markFavorite(): void
    {
        $this->isFavorite = true;
    }

    public function clearFavorite(): void
    {
        $this->isFavorite = false;
    }

    public function isKept(): bool
    {
        return $this->isKept;
    }

    public function markKept(): void
    {
        $this->isKept = true;
    }

    public function clearKept(): void
    {
        $this->isKept = false;
    }

    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    // Restore only: a legacy "read, instant unknown" (null hiddenAt) must survive, which hide() cannot express.
    public function restoreReadMark(BackedUpReadMark $readMark): void
    {
        $this->isHidden = $readMark->isHidden;
        $this->hiddenAt = $readMark->hiddenAt;
    }

    public function isViewed(): bool
    {
        return $this->isViewed;
    }

    public function getViewedAt(): ?\DateTimeImmutable
    {
        return $this->viewedAt;
    }

    /**
     * Records the first open; a repeat open keeps its timestamp. Only opening or the tick sets it, never a
     * mark-all-read sweep. ViewedImpliesHiddenListener hides the entry on flush, so no caller has to.
     */
    public function markViewed(\DateTimeImmutable $when): void
    {
        if ($this->isViewed) {
            return;
        }
        $this->isViewed = true;
        $this->viewedAt = $when;
    }

    /** Un-tick: out of "Recently read" and back in the recommender pool, but still read, so not back in unread. */
    public function clearViewed(): void
    {
        $this->isViewed = false;
        $this->viewedAt = null;
    }

    public function hide(\DateTimeImmutable $when): void
    {
        $this->isHidden = true;
        $this->hiddenAt = $when;
    }

    /** Unread also clears "opened": the entry returns to the recommender pool and leaves "Recently read". */
    public function markUnread(): void
    {
        $this->isHidden = false;
        $this->hiddenAt = null;
        $this->isViewed = false;
        $this->viewedAt = null;
    }
}
