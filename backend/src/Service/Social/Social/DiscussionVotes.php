<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\DiscussionVoteResultModel;
use App\Service\Social\Social\PostVoter\PostVoterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The reader's vote on a post of a network that votes: one per item, cast through the account that cast it first. */
final readonly class DiscussionVotes
{
    public function __construct(
        private ActingAccountChooser $accounts,
        #[AutowireLocator('app.social_post_voter')]
        private ContainerInterface $voters,
        private DiscussionVoteRepository $votes,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private CommentsLoader $comments,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function vote(User $user, Entry $entry, VoteScore $score): DiscussionVoteResultModel
    {
        $network = $this->requireVotingNetwork($entry);
        $item = $this->requireItem($entry);
        $account = $this->votes->findOneFor($user, $item)?->getSocialAccount()
            ?? $this->accounts->requireAccount($user, $entry, $network);

        $result = $this->selectVoter($network)->castVoteOnPost($account, $entry, $score);
        $this->mirror($user, $item, $account, $result->myVote);

        return $result;
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function voteOnComment(
        User $user,
        Entry $entry,
        string $address,
        VoteScore $score,
    ): DiscussionVoteResultModel {
        $network = $this->requireVotingNetwork($entry);
        if (null === AbsoluteHttpUrl::keepIfHttps($address)) {
            throw new NotASocialPostException(sprintf('The address names no %s comment.', $this->formatNetworkNames()));
        }
        $account = $this->accounts->requireAccount($user, $entry, $network);

        $result = $this->selectVoter($network)->castVoteOnComment($account, $address, $score);
        $this->comments->forget($user, $entry);

        return $result;
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function syncFromNetwork(User $user, Entry $entry): VoteScore
    {
        $network = $this->requireVotingNetwork($entry);
        $item = $this->requireItem($entry);
        $account = $this->votes->findOneFor($user, $item)?->getSocialAccount()
            ?? $this->accounts->requireAccount($user, $entry, $network);

        $myVote = $this->selectVoter($network)->fetchPostVote($account, $entry);
        $this->mirror($user, $item, $account, $myVote);

        return $myVote;
    }

    /** @throws NotASocialPostException */
    private function requireVotingNetwork(Entry $entry): SocialNetwork
    {
        foreach ($this->votingNetworks() as $network) {
            if ($this->selectVoter($network)->isVotablePost($entry)) {
                return $network;
            }
        }

        throw new NotASocialPostException(sprintf('This entry is not a %s post.', $this->formatNetworkNames()));
    }

    /** @throws NotASocialPostException */
    private function requireItem(Entry $entry): CanonicalItem
    {
        return $entry->getCanonicalItem()
            ?? throw new NotASocialPostException(sprintf('This entry is not a %s post.', $this->formatNetworkNames()));
    }

    private function selectVoter(SocialNetwork $network): PostVoterInterface
    {
        $voter = $this->voters->get($network->value);
        \assert($voter instanceof PostVoterInterface);

        return $voter;
    }

    /** @return list<SocialNetwork> */
    private function votingNetworks(): array
    {
        $networks = [];
        foreach (SocialNetwork::cases() as $network) {
            if ($this->voters->has($network->value)) {
                $networks[] = $network;
            }
        }

        return $networks;
    }

    private function formatNetworkNames(): string
    {
        $names = array_map(
            static fn (SocialNetwork $network): string => $network->displayName(),
            $this->votingNetworks(),
        );
        $last = (string) array_pop($names);

        return [] === $names ? $last : implode(', ', $names) . ' or ' . $last;
    }

    private function mirror(User $user, CanonicalItem $item, SocialAccount $account, VoteScore $myVote): void
    {
        $stored = $this->votes->findOneFor($user, $item);
        if (null !== $stored && $stored->getSocialAccount()->requireId() !== $account->requireId()) {
            return;
        }
        if (VoteScore::None === $myVote) {
            $this->drop($stored);

            return;
        }
        if (null === $stored) {
            $this->votes->store(new DiscussionVote($user, $item, $account, $myVote, $this->clock->now()));

            return;
        }
        if ($stored->getScore() === $myVote) {
            return;
        }
        $stored->recast($myVote, $this->clock->now());
        $this->entityManager->flush();
    }

    private function drop(?DiscussionVote $stored): void
    {
        if (null === $stored) {
            return;
        }
        $this->entityManager->remove($stored);
        $this->entityManager->flush();
    }
}
