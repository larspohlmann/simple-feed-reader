<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** libcurl 8.22 corrupts memory on an accepted HTTP/2 push and kills the worker (#1146). */
final class DisableHttpServerPushPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('http_client.transport')->setArgument('$maxPendingPushes', 0);
    }
}
