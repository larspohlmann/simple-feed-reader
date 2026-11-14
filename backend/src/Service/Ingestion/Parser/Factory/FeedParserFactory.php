<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Parser\Factory;

use App\Service\Ingestion\Parser\Exception\FeedParseException;
use App\Service\Ingestion\Parser\FeedFormatParser\FeedFormatParserInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class FeedParserFactory
{
    /**
     * The tag comes from services.yaml's `_instanceof`: an `#[AutowireIterator]` on the interface collects nothing
     * (FeedParserWiringTest).
     *
     * @param iterable<FeedFormatParserInterface> $parsers
     */
    public function __construct(
        #[AutowireIterator('app.feed_parser')] private iterable $parsers,
    ) {
    }

    public function parserFor(\DOMElement $root): FeedFormatParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($root)) {
                return $parser;
            }
        }

        throw new FeedParseException(
            sprintf(
                'No parser for feed root <%s> in namespace "%s"',
                $root->localName,
                $root->namespaceURI,
            ),
        );
    }
}
