<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseHeader
{
    public static function first(ResponseInterface $response, string $name): ?string
    {
        try {
            return $response->getHeaders(false)[$name][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private function __construct()
    {
    }
}
