<?php

declare(strict_types=1);

namespace App\Repository;

/** Reads a shared link (aliased `link`) where its short link lands, else where it was posted (#2236). */
final class LinkLandingSql
{
    public const string JOIN = ' LEFT JOIN shared_link_resolution landing ON landing.shared_link_id = link.id';
    public const string URL = 'COALESCE(landing.resolved_url, link.url)';
    public const string URL_HASH = 'COALESCE(landing.resolved_url_hash, link.url_hash)';

    private function __construct()
    {
    }
}
