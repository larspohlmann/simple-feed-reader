<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\ResponseInterface;

/** Where a followed request came to rest: the URL that answered without redirecting, its status, and the open response. */
final readonly class LandedResponse
{
    public function __construct(
        public string $url,
        public int $status,
        public ResponseInterface $response,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
