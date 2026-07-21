<?php

declare(strict_types=1);

namespace App\Service\Fetch;

final readonly class FetchResponse
{
    private function __construct(
        public bool $notModified,
        public string $finalUrl,
        public bool $permanentRedirect,
        public ?string $body,
        public ?string $etag,
        public ?string $lastModified,
    ) {
    }

    public static function fetched(
        string $finalUrl,
        bool $permanentRedirect,
        string $body,
        ?string $etag,
        ?string $lastModified,
    ): self {
        return new self(false, $finalUrl, $permanentRedirect, $body, $etag, $lastModified);
    }

    public static function notModified(
        string $finalUrl,
        bool $permanentRedirect,
        ?string $etag,
        ?string $lastModified,
    ): self {
        return new self(true, $finalUrl, $permanentRedirect, null, $etag, $lastModified);
    }
}
