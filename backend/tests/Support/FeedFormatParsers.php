<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Parser\FeedFormatParser\Atom03Parser;
use App\Service\Parser\FeedFormatParser\Atom10Parser;
use App\Service\Parser\FeedFormatParser\Rss1Parser;
use App\Service\Parser\FeedFormatParser\Rss2Parser;
use App\Service\Parser\FeedItemImageSelector;
use App\Service\Parser\ItemImageExtractor;
use App\Service\Parser\ItemMediaExtractor;

/** The format parsers over the real image and media policies, wired as the container wires them. */
final class FeedFormatParsers
{
    private function __construct()
    {
    }

    public static function rss2(): Rss2Parser
    {
        return new Rss2Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    public static function rss1(): Rss1Parser
    {
        return new Rss1Parser(new ItemImageExtractor(), new ItemMediaExtractor());
    }

    public static function atom10(): Atom10Parser
    {
        return new Atom10Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    public static function atom03(): Atom03Parser
    {
        return new Atom03Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    private static function imageSelector(): FeedItemImageSelector
    {
        return new FeedItemImageSelector(new ItemImageExtractor());
    }
}
