<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\NoVoteToStoreException;
use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A user's vote on a discussion post at its origin, cast through a connected account. */
#[ORM\Entity(repositoryClass: DiscussionVoteRepository::class)]
#[ORM\Table(name: 'discussion_vote')]
#[ORM\Index(name: 'idx_discussion_vote_canonical_item', columns: ['canonical_item_id'])]
#[ORM\Index(name: 'idx_discussion_vote_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_discussion_vote_user_canonical_item', columns: ['user_id', 'canonical_item_id'])]
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
    #[ORM\JoinColumn(name: 'canonical_item_id', nullable: false, onDelete: 'CASCADE')]
    private CanonicalItem $canonicalItem;

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
        CanonicalItem $canonicalItem,
        SocialAccount $socialAccount,
        VoteScore $score,
        \DateTimeImmutable $votedAt,
    ) {
        self::refuseNone($score);
        $this->user = $user;
        $this->canonicalItem = $canonicalItem;
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

    public function getCanonicalItem(): CanonicalItem
    {
        return $this->canonicalItem;
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

    public function recast(VoteScore $score, \DateTimeImmutable $votedAt): void
    {
        self::refuseNone($score);
        $this->score = $score;
        $this->votedAt = $votedAt;
    }

    private static function refuseNone(VoteScore $score): void
    {
        if (VoteScore::None === $score) {
            throw new NoVoteToStoreException();
        }
    }
}
