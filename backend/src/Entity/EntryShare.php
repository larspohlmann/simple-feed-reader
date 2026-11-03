<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryShareRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The reader shared this entry to a social network (#2007); sharing it again moves `sharedAt` on. */
#[ORM\Entity(repositoryClass: EntryShareRepository::class)]
#[ORM\Table(name: 'entry_share')]
#[ORM\Index(name: 'idx_entry_share_user_shared_at', columns: ['user_id', 'shared_at'])]
final class EntryShare
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sharedAt;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, Entry $entry, \DateTimeImmutable $sharedAt)
    {
        $this->user = $user;
        $this->entry = $entry;
        $this->sharedAt = $sharedAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getSharedAt(): \DateTimeImmutable
    {
        return $this->sharedAt;
    }

    public function reshare(\DateTimeImmutable $when): void
    {
        $this->sharedAt = $when;
    }
}
