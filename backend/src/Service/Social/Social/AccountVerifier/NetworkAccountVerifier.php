<?php

declare(strict_types=1);

namespace App\Service\Social\Social\AccountVerifier;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;
use App\Service\Social\Social\NetworkVerifier\NetworkVerifierInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkAccountVerifier implements AccountVerifierInterface
{
    public function __construct(
        #[AutowireLocator('app.social_network_verifier')]
        private ContainerInterface $verifiers,
    ) {
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        return $this->selectVerifier($credentials->network)->verify($credentials);
    }

    public function buildReconnectCredentials(
        SocialAccount $account,
        CredentialReplacementModel $replacement,
    ): SocialCredentialsModel {
        return $this->selectVerifier($account->getNetwork())->buildReconnectCredentials($account, $replacement);
    }

    private function selectVerifier(SocialNetwork $network): NetworkVerifierInterface
    {
        $verifier = $this->verifiers->get($network->value);
        \assert($verifier instanceof NetworkVerifierInterface);

        return $verifier;
    }
}
