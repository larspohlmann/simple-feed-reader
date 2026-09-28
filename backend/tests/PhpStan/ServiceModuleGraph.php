<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

final readonly class ServiceModuleGraph
{
    /** @param array<string, array<string, array{file: string, line: int}>> $sites [module][dependency] => first site */
    private function __construct(private array $sites)
    {
    }

    /** @param array<string, list<list<array{string, string, int}>>> $collected file => the collector's findings */
    public static function fromCollected(array $collected): self
    {
        ksort($collected);
        $sites = [];
        foreach ($collected as $file => $findings) {
            foreach (array_merge(...$findings) as [$module, $dependency, $line]) {
                $sites[$module][$dependency] ??= ['file' => $file, 'line' => $line];
            }
        }

        return new self($sites);
    }

    /** @return list<ServiceModuleCycle> the shortest cycle through each module no earlier cycle passed through */
    public function cycles(): array
    {
        $modules = array_keys($this->sites);
        sort($modules);
        $cycles = [];
        $covered = [];
        foreach ($modules as $module) {
            if (isset($covered[$module])) {
                continue;
            }
            $cycle = $this->shortestCycleThrough($module);
            if (null !== $cycle) {
                $cycles[] = $cycle;
                $covered += array_fill_keys($cycle->modules, true);
            }
        }

        return $cycles;
    }

    private function shortestCycleThrough(string $start): ?ServiceModuleCycle
    {
        $cameFrom = [];
        $queue = [$start];
        for ($next = 0; $next < \count($queue); ++$next) {
            $module = $queue[$next];
            $dependencies = $this->dependenciesOf($module);
            if (\in_array($start, $dependencies, true)) {
                return $this->cycleClosedBy($module, $start, $cameFrom);
            }
            foreach (array_diff($dependencies, [$start, ...array_keys($cameFrom)]) as $unseen) {
                $cameFrom[$unseen] = $module;
                $queue[] = $unseen;
            }
        }

        return null;
    }

    /** @param array<string, string> $cameFrom */
    private function cycleClosedBy(string $last, string $start, array $cameFrom): ServiceModuleCycle
    {
        $site = $this->sites[$last][$start];

        return new ServiceModuleCycle(
            [...self::pathBack($last, $start, $cameFrom), $start],
            $site['file'],
            $site['line'],
        );
    }

    /**
     * @param array<string, string> $cameFrom
     *
     * @return list<string>
     */
    private static function pathBack(string $module, string $start, array $cameFrom): array
    {
        $path = [$module];
        while ($module !== $start) {
            $module = $cameFrom[$module];
            array_unshift($path, $module);
        }

        return $path;
    }

    /** @return list<string> */
    private function dependenciesOf(string $module): array
    {
        $dependencies = array_keys($this->sites[$module] ?? []);
        sort($dependencies);

        return $dependencies;
    }
}
