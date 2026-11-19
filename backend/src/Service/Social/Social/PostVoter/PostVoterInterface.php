<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostVoter;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\VoteScore;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\DiscussionVoteResultModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Casts and reads a vote through the account's own instance; keyed by SocialNetwork, only networks that vote have one. */
#[AutoconfigureTag('app.social_post_voter')]
interface PostVoterInterface
{
    public function isVotablePost(Entry $entry): bool;

    /**
     * @throws NotASocialPostException when isVotablePost() says no
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function castVoteOnPost(SocialAccount $account, Entry $entry, VoteScore $score): DiscussionVoteResultModel;

    /**
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function castVoteOnComment(
        SocialAccount $account,
        string $address,
        VoteScore $score,
    ): DiscussionVoteResultModel;

    /**
     * @throws NotASocialPostException when isVotablePost() says no
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function fetchPostVote(SocialAccount $account, Entry $entry): VoteScore;
}
