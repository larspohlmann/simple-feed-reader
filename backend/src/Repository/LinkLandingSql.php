<?php

declare(strict_types=1);

namespace App\Repository;

/** A link card (aliased `card`) as its entry's first root card, and its shared link (aliased `link`) where its short
 * link lands, else where it was posted (#2236). */
final class LinkLandingSql
{
    public const string JOIN = ' LEFT JOIN shared_link_resolution landing ON landing.shared_link_id = link.id';
    public const string URL = 'COALESCE(landing.resolved_url, link.url)';
    public const string URL_HASH = 'COALESCE(landing.resolved_url_hash, link.url_hash)';

    public const string FIRST_ROOT_CARD = ' card.thread_post_id IS NULL'
        . ' AND card.position = (SELECT MIN(first_card.position) FROM link_card first_card'
        . ' WHERE first_card.entry_id = card.entry_id AND first_card.thread_post_id IS NULL)';

    private function __construct()
    {
    }
}
