<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

final readonly class ServiceModuleCycle
{
    /** @param list<string> $modules round the cycle, the first module again at the end */
    public function __construct(public array $modules, public string $closedInFile, public int $closedOnLine)
    {
    }
}
