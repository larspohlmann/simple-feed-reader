<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Where a module first names a module it depends on. */
final readonly class ServiceModuleDependencySite
{
    public function __construct(public string $file, public int $line)
    {
    }
}
