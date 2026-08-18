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
    private bool $isRead = false;

    #[ORM\Column]
    private bool $isFavorite = false;

    #[ORM\Column]
    private bool $isKept = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

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

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): void
    {
        $this->isRead = $isRead;
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

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeImmutable $readAt): void
    {
        $this->readAt = $readAt;
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
     * "viewed" records that the user actively opened the entry (#307), so a
     * repeat open keeps the first open's timestamp. Only opening sets it —
     * never a mark-all-read sweep or a bare read toggle — which is what lets the
     * recommender treat it as genuine engagement. markUnread() is the one way
     * out again (#478): marking an entry unread means the user has not dealt
     * with it, which contradicts having opened it.
     */
    public function markViewed(\DateTimeImmutable $when): void
    {
        if ($this->isViewed) {
            return;
        }
        $this->isViewed = true;
        $this->viewedAt = $when;
    }

    public function markRead(\DateTimeImmutable $when): void
    {
        $this->isRead = true;
        $this->readAt = $when;
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
        $this->isRead = false;
        $this->readAt = null;
        $this->isViewed = false;
        $this->viewedAt = null;
    }
}
