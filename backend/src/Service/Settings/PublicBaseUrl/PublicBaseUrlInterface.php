<?php

declare(strict_types=1);

namespace App\Service\Settings\PublicBaseUrl;

/**
 * The one externally reachable base URL every mail link is built from: a serving origin such as a LAN host or a
 * proxy hop is useless to a mail read on another device.
 */
interface PublicBaseUrlInterface
{
    /** The configured public base URL, without a trailing slash. */
    public function get(): string;
}
