<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Atom03Parser;
use App\Service\Parser\Atom10Parser;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParserFactory;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use PHPUnit\Framework\TestCase;

final class FeedParserFactoryTest extends TestCase
{
    private function factory(): FeedParserFactory
    {
        return new FeedParserFactory([
            new Rss2Parser(),
            new Atom10Parser(),
            new Atom03Parser(),
            new Rss1Parser(),
        ]);
    }

    private function root(string $xml): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        return $root;
    }

    public function testSelectsRss2ParserForRssRoot(): void
    {
        $parser = $this->factory()->parserFor(
            $this->root('<rss version="2.0"><channel><title>x</title></channel></rss>'),
        );

        self::assertInstanceOf(Rss2Parser::class, $parser);
    }

    public function testSelectsRss1ParserForRdfRoot(): void
    {
        $parser = $this->factory()->parserFor(
            $this->root('<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
                . ' xmlns="http://purl.org/rss/1.0/"><channel><title>x</title></channel></rdf:RDF>'),
        );

        self::assertInstanceOf(Rss1Parser::class, $parser);
    }

    public function testSelectsAtom10ParserForModernAtomNamespace(): void
    {
        $parser = $this->factory()->parserFor(
            $this->root('<feed xmlns="http://www.w3.org/2005/Atom"><title>x</title></feed>'),
        );

        self::assertInstanceOf(Atom10Parser::class, $parser);
    }

    public function testSelectsAtom03ParserForLegacyAtomNamespace(): void
    {
        $parser = $this->factory()->parserFor(
            $this->root('<feed xmlns="http://purl.org/atom/ns#"><title>x</title></feed>'),
        );

        self::assertInstanceOf(Atom03Parser::class, $parser);
    }

    public function testThrowsForUnknownRootElement(): void
    {
        $this->expectException(FeedParseException::class);
        $this->factory()->parserFor($this->root('<unknown><child/></unknown>'));
    }

    public function testThrowsForUnsupportedAtomNamespace(): void
    {
        $this->expectException(FeedParseException::class);
        $this->factory()->parserFor(
            $this->root('<feed xmlns="http://example.com/not-atom"><title>x</title></feed>'),
        );
    }
}
