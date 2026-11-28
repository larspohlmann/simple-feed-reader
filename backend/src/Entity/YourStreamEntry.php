<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\YourStreamMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One entry the For you stream matched for one account. A derived row: the sweep writes it. Naive UTC. */
#[ORM\Entity(repositoryClass: YourStreamMembershipRepository::class)]
#[ORM\Table(name: 'for_you_stream_entry')]
#[ORM\Index(name: 'idx_for_you_stream_entry_entry', columns: ['entry_id'])]
final class YourStreamEntry
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(name: 'matched_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $matchedAt;

    #[ORM\Column(type: Types::FLOAT)]
    private float $score;

    #[ORM\Column(name: 'matched_stems', length: 255)]
    private string $matchedStems;

    public function __construct(
        User $user,
        Entry $entry,
        \DateTimeImmutable $matchedAt,
        float $score,
        string $matchedStems,
    ) {
        $this->user = $user;
        $this->entry = $entry;
        $this->matchedAt = $matchedAt;
        $this->score = $score;
        $this->matchedStems = $matchedStems;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getMatchedAt(): \DateTimeImmutable
    {
        return $this->matchedAt;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getMatchedStems(): string
    {
        return $this->matchedStems;
    }
}
