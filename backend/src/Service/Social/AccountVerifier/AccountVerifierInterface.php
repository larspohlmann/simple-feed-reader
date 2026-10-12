<?php

declare(strict_types=1);

namespace App\Service\Social\AccountVerifier;

use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;

interface AccountVerifierInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel;
}
