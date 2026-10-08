<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** In megabytes. */
enum ResponseSizeLimit: int
{
    /** A feed's wire-byte guard and its buffered-body guard bound the same memory only while both quote this case. */
    case Feed = 20;
    /** Images and the pages fetched for their cookies. */
    case Download = 5;

    public function bytes(): int
    {
        return $this->value * 1_000_000;
    }
}
