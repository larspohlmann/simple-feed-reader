<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

/** A candidate sibling id and where its first occurrence sits on the page. */
final readonly class Sibling
{
    public function __construct(public string $id, public int $position)
    {
    }
}
