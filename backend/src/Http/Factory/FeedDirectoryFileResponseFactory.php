<?php

declare(strict_types=1);

namespace App\Http\Factory;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class FeedDirectoryFileResponseFactory
{
    private const int MAX_AGE_SECONDS = 86400;

    public function json(Request $request, string $path): BinaryFileResponse
    {
        return $this->cached($request, new BinaryFileResponse($path, headers: ['Content-Type' => 'application/json']));
    }

    public function gzippedJson(Request $request, string $path): BinaryFileResponse
    {
        return $this->cached($request, new BinaryFileResponse($path, headers: [
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
        ]));
    }

    private function cached(Request $request, BinaryFileResponse $response): BinaryFileResponse
    {
        $response->setAutoEtag();
        $response->setPrivate();
        $response->setMaxAge(self::MAX_AGE_SECONDS);
        $response->isNotModified($request);

        return $response;
    }
}
