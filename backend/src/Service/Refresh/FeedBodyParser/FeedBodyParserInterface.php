<?php

declare(strict_types=1);

namespace App\Service\Refresh\FeedBodyParser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Model\ParsedFeedModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One strategy for turning a fetched body into a ParsedFeedModel, keyed by the Feed::sourceFormat it owns. Tagged
 * into FeedBodyParser's keyed locator, so a new format is one new class: no dispatcher edit, no list, no match arm.
 */
#[AutoconfigureTag('app.feed_body_parser')]
interface FeedBodyParserInterface
{
    /** The Feed::sourceFormat this parser owns — its key in the locator. */
    public static function format(): string;

    /**
     * @param string $feedUrl the address the body came from; it anchors relative links
     *
     * @throws FeedParseException when the body cannot be read in this format
     */
    public function parse(string $body, string $feedUrl): ParsedFeedModel;
}
