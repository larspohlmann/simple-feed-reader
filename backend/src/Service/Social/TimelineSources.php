<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\TimelineSource\TimelineSourceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class TimelineSources
{
    public function __construct(
        #[AutowireLocator('app.social_timeline_source', defaultIndexMethod: 'network')]
        private ContainerInterface $sources,
    ) {
    }

    public function of(SocialAccount $account): TimelineSourceInterface
    {
        $source = $this->sources->get($account->getNetwork()->value);
        \assert($source instanceof TimelineSourceInterface);

        return $source;
    }
}
