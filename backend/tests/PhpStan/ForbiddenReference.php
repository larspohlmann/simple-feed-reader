<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

final readonly class ForbiddenReference
{
    public function __construct(public string $name, public int $line, public string $matchedRule)
    {
    }
}
