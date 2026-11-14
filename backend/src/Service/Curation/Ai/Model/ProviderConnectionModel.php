<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

/**
 * An endpoint's credentials and its timeouts, decided together from one stored configuration, so no call can use
 * one connection's endpoint with another's patience.
 */
final readonly class ProviderConnectionModel
{
    public function __construct(
        public ProviderCredentialsModel $credentials,
        public ProviderTimeoutsModel $timeouts,
    ) {
    }
}
