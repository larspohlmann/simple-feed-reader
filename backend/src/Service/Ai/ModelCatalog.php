<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;

interface ModelCatalog
{
    /**
     * @return list<string> the model identifiers the provider offers, sorted, never empty
     *
     * @throws CredentialsRejectedException  the provider refused the key
     * @throws ProviderUnreachableException  the provider did not answer usably
     */
    public function listModels(ProviderCredentials $credentials): array;
}
