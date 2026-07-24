<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\ParsedFeed;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One strategy for turning a fetched body into a ParsedFeed, keyed by the
 * Feed::sourceFormat value it owns. Implementations are tagged automatically
 * and collected into FeedBodyParser's keyed locator, indexed by format() —
 * so adding a format to the refresh pipeline is ONE new class implementing
 * this interface: no dispatcher edit, no registration list, no match arm.
 */
#[AutoconfigureTag('app.feed_body_parser')]
interface FeedBodyParserInterface
{
    /** The Feed::sourceFormat this parser owns — its key in the locator. */
    public static function format(): string;

    /** @throws FeedParseException when the body cannot be read in this format */
    public function parse(string $body, Feed $feed): ParsedFeed;
}
