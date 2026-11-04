<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrendingHiddenEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An entry of a Trending story the reader hid (#2017): every story holding it stays out of their list. */
#[ORM\Entity(repositoryClass: TrendingHiddenEntryRepository::class)]
#[ORM\Table(name: 'trending_hidden_entry')]
final class TrendingHiddenEntry
{
    // No `nullable: false` on these identifier join columns: Doctrine forces them NOT NULL and deprecates saying so.
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public readonly User $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public readonly Entry $entry;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $hiddenAt;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, Entry $entry, \DateTimeImmutable $hiddenAt)
    {
        $this->user = $user;
        $this->entry = $entry;
        $this->hiddenAt = $hiddenAt;
    }
}
