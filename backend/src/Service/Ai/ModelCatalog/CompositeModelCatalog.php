<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Pass\ModelListing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CompositeModelCatalog implements ModelCatalogInterface
{
    public const string MEMBER_TAG = 'app.model_catalog';

    /** @param iterable<ModelCatalogInterface> $catalogs by descending priority */
    public function __construct(
        #[AutowireIterator(self::MEMBER_TAG)]
        private iterable $catalogs,
        #[Autowire(service: SystemOneCatalog::class)]
        private ModelCatalogInterface $systemOneFallback,
    ) {
    }

    public function listModels(ProviderCredentialsModel $credentials): array
    {
        $listing = new ModelListing($credentials);
        foreach ($this->catalogs as $catalog) {
            $listing->add($catalog);
        }
        if (!$listing->offers(ScoringProtocol::SystemOne)) {
            $listing->addOverriding($this->systemOneFallback);
        }

        return $listing->models();
    }
}
