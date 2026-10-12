<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineReader;

use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineSource\TimelineSourceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkTimelineReader implements TimelineReaderInterface
{
    public function __construct(
        #[AutowireLocator('app.social_timeline_source', defaultIndexMethod: 'network')]
        private ContainerInterface $sources,
    ) {
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $source = $this->sources->get($socialFeed->getSocialAccount()->getNetwork()->value);
        \assert($source instanceof TimelineSourceInterface);

        return $source->page($socialFeed);
    }
}
