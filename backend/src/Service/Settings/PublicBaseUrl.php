<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * The one absolute base URL every outgoing email points at.
 *
 * An email is read from anywhere — a phone off the LAN, another machine — so a
 * serving origin such as `ganesh.local:3333`, or whatever a Tailscale/SSH-proxy
 * hop assigns, is useless in the link. The instance therefore names its own
 * externally reachable URL once, and every mail link (verification, reset,
 * digest) is built from it, so they cannot drift (#636).
 */
interface PublicBaseUrl
{
    /** The configured public base URL, without a trailing slash. */
    public function get(): string;
}
