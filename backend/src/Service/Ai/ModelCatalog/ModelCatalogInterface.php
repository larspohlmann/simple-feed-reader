<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ModelDescriptor;
use App\Service\Ai\ProviderCredentials;

interface ModelCatalogInterface
{
    /**
     * @return list<ModelDescriptor> sorted by id, unique ids, never empty
     *
     * @throws CredentialsRejectedException  the provider refused the key
     * @throws ProviderUnreachableException  the provider did not answer usably
     */
    public function listModels(ProviderCredentials $credentials): array;
}
