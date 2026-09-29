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
}
