<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Image\Model\ProxiedImageModel;
use Symfony\Component\HttpFoundation\Response;

final class ProxiedImageResponse
{
    private const int MAX_AGE_SECONDS = 86400;

    public static function of(ProxiedImageModel $image): Response
    {
        $response = new Response($image->bytes, Response::HTTP_OK, [
            'Content-Type' => $image->contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
        $response->setPrivate();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
