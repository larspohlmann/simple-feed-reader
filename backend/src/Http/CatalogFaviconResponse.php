<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Catalog\CatalogFavicon;
use Symfony\Component\HttpFoundation\Response;

final class CatalogFaviconResponse
{
    /** A day is safe: the URL is per feed id, and the ETag changes whenever the bytes do. */
    private const int MAX_AGE_SECONDS = 86400;

    public static function of(CatalogFavicon $favicon): Response
    {
        $response = new Response($favicon->bytes, Response::HTTP_OK, ['Content-Type' => $favicon->contentType]);
        $response->setEtag(md5($favicon->bytes));
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
