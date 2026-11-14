<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling\Model;

/** A candidate sibling id and where its first occurrence sits on the page. */
final readonly class SiblingModel
{
    public function __construct(public string $id, public int $position)
    {
    }
}
