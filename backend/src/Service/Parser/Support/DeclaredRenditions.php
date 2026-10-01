<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\ImageRendition;
use App\Service\Html\Support\Srcset;

final class DeclaredRenditions
{
    /**
     * Only a `w` descriptor states a file's pixel width; a density or bare candidate says nothing about it.
     *
     * @return list<ImageRendition>
     */
    public static function fromSrcset(?string $srcset): array
    {
        $renditions = [];
        foreach (Srcset::candidates($srcset) as $candidate) {
            if ($candidate->width !== null && $candidate->width > 0) {
                $renditions[] = new ImageRendition($candidate->url, $candidate->width);
            }
        }

        return $renditions;
    }

    /** @return list<ImageRendition> */
    public static function ofWidth(string $url, ?int $width): array
    {
        return $width === null ? [] : [new ImageRendition($url, $width)];
    }

    private function __construct()
    {
    }
}
