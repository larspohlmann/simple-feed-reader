<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InterestTermSetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One row per user holding the stream's sweep mark; the terms live in interest_term. Naive UTC. */
#[ORM\Entity(repositoryClass: InterestTermSetRepository::class)]
#[ORM\Table(name: 'interest_term_set')]
final class InterestTermSet
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $profileGeneratedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $storedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $raritiesRefreshedAt = null;

    #[ORM\Column(name: 'matched_up_to_entry_id', options: ['default' => 0])]
    private int $matchedUpToEntryId;

    /** @var list<array{language: string, phrase: string, weight: float}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $phrases = null;

    public function __construct(
        User $user,
        \DateTimeImmutable $profileGeneratedAt,
        \DateTimeImmutable $storedAt,
        int $matchedUpToEntryId,
    ) {
        $this->user = $user;
        $this->profileGeneratedAt = $profileGeneratedAt;
        $this->storedAt = $storedAt;
        $this->matchedUpToEntryId = $matchedUpToEntryId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProfileGeneratedAt(): \DateTimeImmutable
    {
        return $this->profileGeneratedAt;
    }

    public function getStoredAt(): \DateTimeImmutable
    {
        return $this->storedAt;
    }

    public function getRaritiesRefreshedAt(): ?\DateTimeImmutable
    {
        return $this->raritiesRefreshedAt;
    }

    public function matchedUpToEntryId(): int
    {
        return $this->matchedUpToEntryId;
    }

    public function replaceTerms(\DateTimeImmutable $profileGeneratedAt, \DateTimeImmutable $storedAt): void
    {
        $this->profileGeneratedAt = $profileGeneratedAt;
        $this->storedAt = $storedAt;
        $this->raritiesRefreshedAt = null;
    }

    /** @param list<InterestPhrase> $phrases */
    public function recordPhrases(array $phrases): void
    {
        $this->phrases = array_map(
            static fn (InterestPhrase $phrase): array => $phrase->toStoredArray(),
            $phrases,
        );
    }

    /** @return list<InterestPhrase> */
    public function getPhrases(): array
    {
        return array_map(InterestPhrase::fromStoredArray(...), $this->phrases ?? []);
    }

    public function recordRaritiesRefreshed(\DateTimeImmutable $refreshedAt): void
    {
        $this->raritiesRefreshedAt = $refreshedAt;
    }
}
