<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

/** The host a link's card is named after: where it lands, or the posted host when the landing is a subdomain of it. */
final class LandingSite
{
    public static function pickHost(string $postedUrl, string $landingUrl): string
    {
        $postedHost = HostKey::forUrl($postedUrl);
        $landingHost = HostKey::forUrl($landingUrl);

        return str_ends_with($landingHost, '.' . $postedHost) ? $postedHost : $landingHost;
    }

    private function __construct()
    {
    }
}
