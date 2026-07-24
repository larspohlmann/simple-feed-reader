<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The Feed::sourceFormat vocabulary: how a fetched body is turned into
 * entries. Deliberately a constants holder and NOT a backed enum, unlike its
 * neighbours here — the value set is open by design: refresh parsers register
 * formats via the app.feed_body_parser container tag (see
 * FeedBodyParserInterface), and a row may carry a value this deployment does
 * not know (written by a newer version, or by a strategy since removed).
 * Exhaustive enum matching would defeat that seam; these consts just make
 * sure the two known values are written once.
 */
final class SourceFormat
{
    /** RSS/Atom feed documents — the pre-scraper default of every row. */
    public const string XML = 'xml';

    /** Feeds synthesized from a plain HTML page by the item extractor. */
    public const string SCRAPED = 'scraped';
}
