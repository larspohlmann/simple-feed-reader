<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Settings\PublicBaseUrl\PublicBaseUrlInterface;

/** A PublicBaseUrlInterface that always resolves to one fixed value, for tests. */
final readonly class FixedPublicBaseUrl implements PublicBaseUrlInterface
{
    public function __construct(private string $base)
    {
    }

    public function get(): string
    {
        return $this->base;
    }
}
