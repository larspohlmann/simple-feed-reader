<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Content\Comments\Support\LemmyPostAddress;
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
use Doctrine\ORM\EntityManagerInterface;

/** The reader's vote on a Lemmy post: cast through their account, kept as the network reports it. */
final readonly class DiscussionVotes
{
    public function __construct(
        private ActingAccountChooser $accounts,
        private LemmyVoting $voting,
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
        $post = self::postOf($entry);
        $account = $this->accounts->accountFor($user, $entry, SocialNetwork::Lemmy);

        $target = $this->voting->castOnPost($account, $post, $score);
        $this->mirror($user, $entry, $account, $target->myVote);

        return new DiscussionVoteResultModel($target->myVote, $target->score);
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
        self::postOf($entry);
        if (null === AbsoluteHttpUrl::httpsOrNull($address)) {
            throw new NotASocialPostException('The address names no Lemmy comment.');
        }
        $account = $this->accounts->accountFor($user, $entry, SocialNetwork::Lemmy);

        $target = $this->voting->castOnComment($account, $address, $score);
        $this->comments->forget($user, $entry);

        return new DiscussionVoteResultModel($target->myVote, $target->score);
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
        $post = self::postOf($entry);
        $account = $this->accounts->accountFor($user, $entry, SocialNetwork::Lemmy);

        $myVote = $this->voting->readPost($account, $post)->myVote;
        $this->mirror($user, $entry, $account, $myVote);

        return $myVote;
    }

    /** @throws NotASocialPostException */
    private static function postOf(Entry $entry): LemmyPostModel
    {
        return LemmyPostAddress::of($entry) ?? throw new NotASocialPostException('This entry is not a Lemmy post.');
    }

    private function mirror(User $user, Entry $entry, SocialAccount $account, VoteScore $myVote): void
    {
        $stored = $this->votes->findOneFor($user, $entry);
        if (VoteScore::None === $myVote) {
            $this->drop($stored);

            return;
        }
        if (null === $stored) {
            $this->votes->store(new DiscussionVote($user, $entry, $account, $myVote, $this->clock->now()));

            return;
        }
        $stored->recast($account, $myVote, $this->clock->now());
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
