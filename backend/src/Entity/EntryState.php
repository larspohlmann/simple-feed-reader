<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryStateRepository::class)]
#[ORM\Table(name: 'entry_state')]
class EntryState
{
    // No `nullable: false` on these two join columns: they are part of the
    // composite identifier, and Doctrine forces identifier join columns to
    // NOT NULL regardless. Stating it is a no-op the ORM deprecates (and warns
    // about in dev.log), so only onDelete — which is a real choice — remains.
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

    public function setIsHidden(bool $isHidden): void
    {
        $this->isHidden = $isHidden;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(bool $isFavorite): void
    {
        $this->isFavorite = $isFavorite;
    }

    public function isKept(): bool
    {
        return $this->isKept;
    }

    public function setIsKept(bool $isKept): void
    {
        $this->isKept = $isKept;
    }

    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    public function setHiddenAt(?\DateTimeImmutable $hiddenAt): void
    {
        $this->hiddenAt = $hiddenAt;
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
     * "viewed" records that the user actively opened and read the entry (#307),
     * so a repeat open keeps the first open's timestamp. Only opening or the tick
     * sets it — never a mark-all-read sweep. It sets the viewed flag alone; the
     * subset invariant (viewed ⇒ hidden) is enforced centrally on flush by
     * ViewedImpliesHiddenListener (#482), so no caller has to remember the coupling.
     */
    public function markViewed(\DateTimeImmutable $when): void
    {
        if ($this->isViewed) {
            return;
        }
        $this->isViewed = true;
        $this->viewedAt = $when;
    }

    /**
     * Un-tick (#482): the user is no longer counted as having read the article,
     * so it drops out of "Recently read" and returns to the recommender pool. The
     * read flag stays — being read is sticky, so the entry does not come back to
     * the unread list.
     */
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

    /**
     * Marking an entry unread also clears "opened": the two describe the same
     * act from the user's side (#478), so unread returns the entry to the
     * recommender's candidate pool and drops it from the "Recently read" list.
     * A bare read toggle never set "opened" in the first place, so an entry the
     * user only marked read — never opened — simply has nothing to clear here.
     */
    public function markUnread(): void
    {
        $this->isHidden = false;
        $this->hiddenAt = null;
        $this->isViewed = false;
        $this->viewedAt = null;
    }
}
