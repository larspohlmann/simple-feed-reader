<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The catalog every caller sees: each member that recognises the provider adds its models. When none does, the first
 * member's failure is the answer, so an address without System One reads exactly as the OpenAI catalog says.
 */
final readonly class CompositeModelCatalog implements ModelCatalogInterface
{
    public const string MEMBER_TAG = 'app.model_catalog';

    /** @param iterable<ModelCatalogInterface> $catalogs by descending priority */
    public function __construct(
        #[AutowireIterator(self::MEMBER_TAG)]
        private iterable $catalogs,
    ) {
    }

    public function listModels(ProviderCredentialsModel $credentials): array
    {
        $byId = [];
        $failures = [];
        foreach ($this->catalogs as $catalog) {
            try {
                foreach ($catalog->listModels($credentials) as $descriptor) {
                    $byId[$descriptor->id] ??= $descriptor;
                }
            } catch (CredentialsRejectedException | ProviderUnreachableException $failure) {
                $failures[] = $failure;
            }
        }

        if ([] === $byId) {
            throw $failures[0] ?? new ProviderUnreachableException('That provider offers no models.');
        }
        ksort($byId, \SORT_STRING);

        return array_values($byId);
    }
}
