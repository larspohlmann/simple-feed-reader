<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Parser\ParsedFeed;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Dispatches a feed's body to the parser owning its sourceFormat. The keyed
 * locator is fed by the app.feed_body_parser tag with each parser's static
 * format() as its index, so the dispatcher never needs to know the concrete
 * strategies — see FeedBodyParserInterface for the extension contract.
 */
final readonly class FeedBodyParser
{
    public function __construct(
        #[AutowireLocator('app.feed_body_parser', defaultIndexMethod: 'format')]
        private ContainerInterface $parsers,
    ) {
    }

    public function parse(Feed $feed, string $body): ParsedFeed
    {
        $format = $feed->getSourceFormat();
        if (!$this->parsers->has($format)) {
            // Defensive fallback for rows whose format has no parser here —
            // written by a newer deployment, or left behind by a removed
            // strategy. 'xml' is what every row meant before the seam existed,
            // and its parse failure lands in the runner's normal error
            // handling instead of a locator NotFoundException escaping it.
            $format = XmlBodyParser::format();
        }

        $parser = $this->parsers->get($format);
        \assert($parser instanceof FeedBodyParserInterface);

        return $parser->parse($body, $feed);
    }
}
