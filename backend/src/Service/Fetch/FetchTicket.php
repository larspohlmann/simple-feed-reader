<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * One feed's fetch request. Bundles the conditional-GET headers with the URL so
 * a batch can carry them together and callers stop passing three positionals.
 */
final readonly class FetchTicket
{
    public function __construct(
        public string $url,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }
}
