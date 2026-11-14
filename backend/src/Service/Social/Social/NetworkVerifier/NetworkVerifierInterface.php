<?php

declare(strict_types=1);

namespace App\Service\Social\Social\NetworkVerifier;

use App\Service\Social\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's live credential check, keyed by its SocialNetwork value in NetworkAccountVerifier's locator. */
#[AutoconfigureTag('app.social_network_verifier')]
interface NetworkVerifierInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel;
}
