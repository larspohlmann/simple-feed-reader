<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ModelDescriptorModel;
use App\Service\Ai\Model\ProviderCredentialsModel;

interface ModelCatalogInterface
{
    /**
     * @return list<ModelDescriptorModel> sorted by id, unique ids, never empty
     *
     * @throws CredentialsRejectedException  the provider refused the key
     * @throws ProviderUnreachableException  the provider did not answer usably
     */
    public function listModels(ProviderCredentialsModel $credentials): array;
}
