<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** In kilobytes. */
enum ResponseSizeLimit: int
{
    /** A feed's wire-byte guard and its buffered-body guard bound the same memory only while both quote this case. */
    case Feed = 20_000;
    /** Images and the pages fetched for their cookies. */
    case Download = 5_000;
    case Api = 2_000;
    case ChapterFile = 64;
    case EmbedDescription = 512;
    case TranscriptFile = 1_000;

    public function bytes(): int
    {
        return $this->value * 1_000;
    }

    public function label(): string
    {
        if ($this->value % 1_000 === 0) {
            return sprintf('%g MB', $this->value / 1_000);
        }

        return sprintf('%d kB', $this->value);
    }
}
