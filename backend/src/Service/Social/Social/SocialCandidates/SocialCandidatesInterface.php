<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SocialCandidates;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Social\Model\NetworkCollectionsModel;

interface SocialCandidatesInterface
{
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
