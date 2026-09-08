<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Where a followed request lands: the URL that answered without redirecting, its status, response, and hop count.
 */
final readonly class LandedResponse
{
    public function __construct(
        public string $url,
        public int $status,
        public ResponseInterface $response,
        public int $hops = 0,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        try {
            return $this->response->getHeaders(false)[$name][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
