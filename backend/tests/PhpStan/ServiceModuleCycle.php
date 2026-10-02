<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

final readonly class ServiceModuleCycle
{
    /**
     * @param list<string> $modules round the cycle, the first module again at the end
     * @param non-empty-list<ServiceModuleDependencySite> $sites the first site of each hop: $sites[$hop] is where
     *                                                 $modules[$hop] names $modules[$hop + 1]
     */
    public function __construct(public array $modules, public array $sites)
    {
    }

    /** A parent naming its own sub-module is the offending hop by design; between peers, the hop that closes it. */
    public function reportedSite(): ServiceModuleDependencySite
    {
        return array_find(
            $this->sites,
            fn (ServiceModuleDependencySite $site, int $hop): bool
                => ServiceModules::isSubModuleOf($this->modules[$hop + 1], $this->modules[$hop]),
        ) ?? $this->sites[array_key_last($this->sites)];
    }
}
