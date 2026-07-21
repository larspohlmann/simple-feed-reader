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
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column]
    private bool $isRead = false;

    #[ORM\Column]
    private bool $isFavorite = false;

    #[ORM\Column]
    private bool $isKept = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

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
}
