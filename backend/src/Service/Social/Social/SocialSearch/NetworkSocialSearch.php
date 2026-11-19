<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\SearchSource\SearchSourceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkSocialSearch implements SocialSearchInterface
{
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_search_source')]
        private ContainerInterface $sources,
    ) {
    }

    public function newest(SocialAccount $account, string $query): TimelinePageModel
    {
        $source = $this->sources->get($account->getNetwork()->value);
        \assert($source instanceof SearchSourceInterface);

        return $this->guard->actAs($account, static fn (): TimelinePageModel => $source->fetchNewest($account, $query));
    }
}
