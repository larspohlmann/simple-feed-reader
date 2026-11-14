<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\StoryLinkSource\StoryLinkSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

final readonly class StoryLinks
{
    /** @param ServiceProviderInterface<StoryLinkSourceInterface> $sources */
    public function __construct(
        #[AutowireLocator('app.story_link_source')]
        private ServiceProviderInterface $sources,
    ) {
    }

    /** @return list<StoryLinkModel> every linked pair once, at the strength of its strongest source */
    public function between(TrendingRun $run): array
    {
        $strongest = [];
        foreach (array_keys($this->sources->getProvidedServices()) as $key) {
            foreach ($this->sources->get($key)->links($run) as $link) {
                $known = $strongest[$link->pairKey()] ?? null;
                if ($known === null || $link->strength > $known->strength) {
                    $strongest[$link->pairKey()] = $link;
                }
            }
        }

        return array_values($strongest);
    }
}
