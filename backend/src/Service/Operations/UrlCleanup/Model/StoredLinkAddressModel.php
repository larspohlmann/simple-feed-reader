<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup\Model;

final readonly class StoredLinkAddressModel
{
    public function __construct(
        public int $id,
        public string $url,
        public string $urlHash,
    ) {
    }
}
