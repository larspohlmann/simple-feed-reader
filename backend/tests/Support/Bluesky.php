<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Values the Bluesky tests and the recorded fixtures share. */
final class Bluesky
{
    public const string GET_POSTS = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts';
    public const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';

    private function __construct()
    {
    }
}
