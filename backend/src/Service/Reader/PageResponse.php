<?php

declare(strict_types=1);

namespace App\Service\Reader;

final readonly class PageResponse
{
    public function __construct(
        public string $finalUrl,
        public string $html,
    ) {
    }
}
