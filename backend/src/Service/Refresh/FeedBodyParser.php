<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\ParsedFeed;
use App\Service\Refresh\FeedBodyParser\FeedBodyParserInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Dispatches a feed's body to the parser owning its sourceFormat, through the app.feed_body_parser keyed locator
 * (see FeedBodyParserInterface for the extension contract).
 */
final readonly class FeedBodyParser
{
    public function __construct(
        #[AutowireLocator('app.feed_body_parser', defaultIndexMethod: 'format')]
        private ContainerInterface $parsers,
    ) {
    }

    /** @throws FeedParseException */
    public function parse(Feed $feed, string $body): ParsedFeed
    {
        $format = $feed->getSourceFormat();
        if ($this->parsers->has($format)) {
            return $this->resolve($format)->parse($body, $feed);
        }

        // A row whose format has no parser here (a newer deployment's, or a removed strategy's) is read as xml,
        // which is what every row meant before the seam existed.
        try {
            return $this->resolve(SourceFormat::XML)->parse($body, $feed);
        } catch (FeedParseException $e) {
            throw new FeedParseException(
                sprintf('No parser for source format "%s"; tried xml: %s', $format, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function resolve(string $format): FeedBodyParserInterface
    {
        try {
            $parser = $this->parsers->get($format);
        } catch (ContainerExceptionInterface $e) {
            throw new \LogicException(sprintf('No feed body parser is wired for "%s".', $format), previous: $e);
        }
        \assert($parser instanceof FeedBodyParserInterface);

        return $parser;
    }
}
