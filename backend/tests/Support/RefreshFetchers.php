<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Puts both of a container refresh's fetches behind stubs: the pass's feeds, and the favicon pass's homepage. */
final class RefreshFetchers
{
    private function __construct()
    {
    }

    public static function install(ContainerInterface $container, StubPassFetcher $passes, string $homepage): void
    {
        $homepages = new StubFeedFetcher();
        $homepages->willReturn(
            $homepage,
            FetchResponseModel::fetched($homepage, false, '<html lang="en"></html>', null, null),
        );
        $container->set(PassFetcherInterface::class, $passes);
        $container->set(BatchFeedFetcherInterface::class, $homepages);
    }
}
