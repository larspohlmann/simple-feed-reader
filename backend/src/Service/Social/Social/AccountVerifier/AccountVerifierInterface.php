<?php

declare(strict_types=1);

namespace App\Service\Social\Social\AccountVerifier;

use App\Service\Social\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;

interface AccountVerifierInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel;
}
