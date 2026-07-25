<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the parser for a feed document from its root element. The dialect
 * knowledge lives in each parser's supports(); this only walks the registered
 * parsers in order and returns the first match, so adding a dialect means adding
 * a parser, not editing a central match.
 */
final readonly class FeedParserFactory
{
    /**
     * The 'app.feed_parser' tag is applied to every FeedFormatParserInterface by
     * the `_instanceof` block in services.yaml; a bare
     * `#[AutowireIterator(FeedFormatParserInterface::class)]` would collect
     * nothing, exactly as documented for OAuthProviderRegistry.
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
                (string) $root->namespaceURI,
            ),
        );
    }
}
