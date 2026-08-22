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
        public ?ProxyConfig $proxy = null,
    ) {
    }

    /** The same request routed through an egress proxy (or unchanged when null). */
    public function withProxy(?ProxyConfig $proxy): self
    {
        return new self($this->url, $this->etag, $this->lastModified, $proxy);
    }
}
