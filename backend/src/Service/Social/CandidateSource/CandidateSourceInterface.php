<?php

declare(strict_types=1);

namespace App\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\NetworkCollectionsModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's follows, lists and custom feeds, keyed by its SocialNetwork value in NetworkSocialCandidates' locator. */
#[AutoconfigureTag('app.social_candidate_source')]
interface CandidateSourceInterface
{
    public static function network(): string;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function candidates(SocialAccount $account): NetworkCandidatesModel;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function collections(SocialAccount $account): NetworkCollectionsModel;
}
