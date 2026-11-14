<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A user's vote on a discussion post at its origin (#2218), cast through a connected account. */
#[ORM\Entity(repositoryClass: DiscussionVoteRepository::class)]
#[ORM\Table(name: 'discussion_vote')]
#[ORM\Index(name: 'idx_discussion_vote_entry', columns: ['entry_id'])]
#[ORM\Index(name: 'idx_discussion_vote_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_discussion_vote_user_entry', columns: ['user_id', 'entry_id'])]
final class DiscussionVote
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialAccount $socialAccount;

    #[ORM\Column(type: Types::SMALLINT, enumType: VoteScore::class)]
    private VoteScore $score;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $votedAt;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        User $user,
        Entry $entry,
        SocialAccount $socialAccount,
        VoteScore $score,
        \DateTimeImmutable $votedAt,
    ) {
        $this->user = $user;
        $this->entry = $entry;
        $this->socialAccount = $socialAccount;
        $this->score = $score;
        $this->votedAt = $votedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getSocialAccount(): SocialAccount
    {
        return $this->socialAccount;
    }

    public function getScore(): VoteScore
    {
        return $this->score;
    }

    public function getVotedAt(): \DateTimeImmutable
    {
        return $this->votedAt;
    }

    public function recast(SocialAccount $account, VoteScore $score, \DateTimeImmutable $votedAt): void
    {
        $this->socialAccount = $account;
        $this->score = $score;
        $this->votedAt = $votedAt;
    }
}
