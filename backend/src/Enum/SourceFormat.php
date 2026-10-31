<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The Feed::sourceFormat vocabulary. Constants, not a backed enum: parsers register formats through the
 * app.feed_body_parser tag, and a row may carry a value this deployment does not know.
 */
final class SourceFormat
{
    /** RSS/Atom feed documents; the column default. */
    public const string XML = 'xml';

    /** Feeds synthesized from a plain HTML page by the item extractor. */
    public const string SCRAPED = 'scraped';

    /** WordPress REST posts endpoint (wp/v2/posts with `_fields`, never `_embed`): full-content JSON. */
    public const string WP_JSON = 'wp-json';

    /** A Mixcloud user's shows, read through the public cloudcasts API (#1932). */
    public const string MIXCLOUD_API = 'mixcloud-api';

    /** The formats a discovery candidate is subscribed and previewed by as offered, without discovery again. */
    public const array VERBATIM = [self::SCRAPED, self::WP_JSON, self::MIXCLOUD_API];

    /** A Bluesky timeline, list or custom feed, read through the account's session (#1599). */
    public const string BLUESKY_API = 'bluesky-api';

    /** A Mastodon home or list timeline, read with the account's token (#1599). */
    public const string MASTODON_API = 'mastodon-api';

    /** The formats of private feeds: read through an account, never fetched by URL, never exported. */
    public const array SOCIAL = [self::BLUESKY_API, self::MASTODON_API];

    /** A user's "Saved from …" feed of favourites and kept posts from feeds they left (#1666); never fetched. */
    public const string SAVED = 'saved';

    /** Formats whose networked feed is no person's profile. */
    public const array NOT_PROFILES = [self::BLUESKY_API, self::MASTODON_API, self::SAVED];

    /** Formats an OPML export leaves out: a scraped feed is this reader's own reading of a page (#1934). */
    public const array NOT_IN_OPML = [...self::NOT_PROFILES, self::SCRAPED];

    public static function isSocial(string $sourceFormat): bool
    {
        return \in_array($sourceFormat, self::SOCIAL, true);
    }

    public static function isSaved(string $sourceFormat): bool
    {
        return self::SAVED === $sourceFormat;
    }

    /** @phpstan-assert-if-true value-of<self::VERBATIM> $sourceFormat */
    public static function isVerbatim(?string $sourceFormat): bool
    {
        return \in_array($sourceFormat, self::VERBATIM, true);
    }
}
