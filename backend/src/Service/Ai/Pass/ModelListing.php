<?php

declare(strict_types=1);

namespace App\Service\Ai\Pass;

use App\Entity\ModelDescriptor;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;

/** One composite listing: the models by id, and the failures of the catalogs that did not recognise the provider. */
final class ModelListing
{
    /** @var array<string, ModelDescriptor> */
    private array $byId = [];

    /** @var list<CredentialsRejectedException|ProviderUnreachableException> */
    private array $failures = [];

    public function __construct(private readonly ProviderCredentialsModel $credentials)
    {
    }

    public function add(ModelCatalogInterface $catalog): void
    {
        $this->byId += $this->answerOf($catalog);
    }

    /** The catalog's description wins an id this listing holds already. */
    public function addOverriding(ModelCatalogInterface $catalog): void
    {
        $this->byId = $this->answerOf($catalog) + $this->byId;
    }

    public function offers(ScoringProtocol $protocol): bool
    {
        return array_any(
            $this->byId,
            static fn (ModelDescriptor $model): bool => $protocol === $model->scoringProtocol,
        );
    }

    /**
     * @return list<ModelDescriptor> sorted by id
     *
     * @throws CredentialsRejectedException|ProviderUnreachableException the first failure, when no catalog answered
     */
    public function models(): array
    {
        if ([] === $this->byId) {
            throw $this->failures[0] ?? new ProviderUnreachableException('That provider offers no models.');
        }
        $byId = $this->byId;
        ksort($byId, \SORT_STRING);

        return array_values($byId);
    }

    /** @return array<string, ModelDescriptor> the catalog's first description per id; none when it failed */
    private function answerOf(ModelCatalogInterface $catalog): array
    {
        try {
            $byId = [];
            foreach ($catalog->listModels($this->credentials) as $model) {
                $byId[$model->id] ??= $model;
            }

            return $byId;
        } catch (CredentialsRejectedException | ProviderUnreachableException $failure) {
            $this->failures[] = $failure;

            return [];
        }
    }
}
