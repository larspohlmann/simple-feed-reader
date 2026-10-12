<?php

declare(strict_types=1);

namespace App\Service\Social\SocialCandidates;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\NetworkCandidatesModel;

interface SocialCandidatesInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function candidates(SocialAccount $account): NetworkCandidatesModel;
}
