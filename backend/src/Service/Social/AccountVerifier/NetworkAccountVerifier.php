<?php

declare(strict_types=1);

namespace App\Service\Social\AccountVerifier;

use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use App\Service\Social\NetworkVerifier\NetworkVerifierInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkAccountVerifier implements AccountVerifierInterface
{
    public function __construct(
        #[AutowireLocator('app.social_network_verifier', defaultIndexMethod: 'network')]
        private ContainerInterface $verifiers,
    ) {
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $verifier = $this->verifiers->get($credentials->network->value);
        \assert($verifier instanceof NetworkVerifierInterface);

        return $verifier->verify($credentials);
    }
}
