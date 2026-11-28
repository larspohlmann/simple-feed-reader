<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\YourStreamStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One row per account: how far the stream has matched, and what its word list was last built from. Naive UTC. */
#[ORM\Entity(repositoryClass: YourStreamStateRepository::class)]
#[ORM\Table(name: 'your_stream_state')]
final class YourStreamState
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'matched_up_to_entry_id', options: ['default' => 0])]
    private int $matchedUpToEntryId;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $interestFingerprint = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $wordsCheckedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $wordsBuiltAt = null;

    public function __construct(User $user, int $matchedUpToEntryId)
    {
        $this->user = $user;
        $this->matchedUpToEntryId = $matchedUpToEntryId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function matchedUpToEntryId(): int
    {
        return $this->matchedUpToEntryId;
    }

    public function isBuiltFrom(string $fingerprint, \DateTimeImmutable $builtAfter): bool
    {
        return $this->interestFingerprint === $fingerprint
            && null !== $this->wordsBuiltAt
            && $this->wordsBuiltAt > $builtAfter;
    }

    public function recordChecked(\DateTimeImmutable $checkedAt): void
    {
        $this->wordsCheckedAt = $checkedAt;
    }

    public function recordBuilt(string $fingerprint, \DateTimeImmutable $builtAt): void
    {
        $this->interestFingerprint = $fingerprint;
        $this->wordsBuiltAt = $builtAt;
        $this->wordsCheckedAt = $builtAt;
    }
}
