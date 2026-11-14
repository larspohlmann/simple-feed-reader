<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\HTMLDocument;

final class MetaTags
{
    /** @return array<string, string> each meta property or name, lower-cased, with the content of its first tag */
    public static function contentByKey(HTMLDocument $document): array
    {
        $contentByKey = [];
        foreach ($document->querySelectorAll('meta[content]') as $meta) {
            $key = strtolower((string) ($meta->getAttribute('property') ?? $meta->getAttribute('name')));
            if ($key !== '' && !isset($contentByKey[$key])) {
                $contentByKey[$key] = (string) $meta->getAttribute('content');
            }
        }

        return $contentByKey;
    }

    private function __construct()
    {
    }
}
