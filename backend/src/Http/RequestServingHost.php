<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Settings\PublicBaseUrl;
use App\Service\Settings\ServingHost;
use Symfony\Component\HttpFoundation\RequestStack;

/** A proxy that rewrites Host rather than passing it through needs
 *  SYMFONY_TRUSTED_PROXIES set for this to see the real one. */
final readonly class RequestServingHost implements ServingHost
{
    public function __construct(
        private RequestStack $requests,
        private PublicBaseUrl $publicBaseUrl,
    ) {
    }

    public function get(): string
    {
        $request = $this->requests->getMainRequest();
        if (null !== $request) {
            return $request->getHost();
        }

        $host = parse_url($this->publicBaseUrl->get(), PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }
}
