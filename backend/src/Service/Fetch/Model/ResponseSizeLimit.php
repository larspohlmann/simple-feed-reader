<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

enum ResponseSizeLimit: int
{
    /** A feed's wire-byte guard and its buffered-body guard bound the same memory only while both quote this case. */
    case Feed = 20_000_000;
    /** Images and the pages fetched for their cookies. */
    case Download = 5_000_000;

    public function megabytes(): int
    {
        return intdiv($this->value, 1_000_000);
    }
}
