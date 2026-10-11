<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

final readonly class PageResponseModel
{
    public function __construct(
        public string $finalUrl,
        public string $html,
    ) {
    }
}
