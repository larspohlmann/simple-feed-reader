<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FeedParserTest builds the factory with a hand-listed parser array, so it
 * stays green even if the container's 'app.feed_parser' tag collects nothing —
 * the same silent empty-iterator failure OAuthProviderWiringTest documents. This
 * drives the REAL container wiring: every dialect must resolve through the
 * tagged iterator, proving FeedFormatParserInterface is actually tagged.
 */
final class FeedParserWiringTest extends KernelTestCase
{
    private function parser(): FeedParser
    {
        self::bootKernel();
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);

        return $parser;
    }

    public function testRss2ResolvesThroughTheTaggedIterator(): void
    {
        $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>Wired</title>'
            . '<item><title>Post</title><link>https://example.com/p</link><guid>w-1</guid></item>'
            . '</channel></rss>';

        $feed = $this->parser()->parse($rss);

        self::assertSame('Wired', $feed->title);
        self::assertCount(1, $feed->entries);
    }

    public function testRss1ResolvesThroughTheTaggedIterator(): void
    {
        $rdf = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
            . ' xmlns="http://purl.org/rss/1.0/"><channel><title>RDF Feed</title></channel>'
            . '<item><title>Item</title><link>https://example.com/i</link></item></rdf:RDF>';

        $feed = $this->parser()->parse($rdf);

        self::assertSame('RDF Feed', $feed->title);
    }

    public function testAtom10ResolvesThroughTheTaggedIterator(): void
    {
        $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom</title>'
            . '<entry><title>E</title><link href="https://example.com/e" rel="alternate"/></entry></feed>';

        $feed = $this->parser()->parse($atom);

        self::assertSame('Atom', $feed->title);
        self::assertCount(1, $feed->entries);
    }

    public function testAtom03ResolvesThroughTheTaggedIterator(): void
    {
        $atom = '<?xml version="1.0"?><feed xmlns="http://purl.org/atom/ns#"><title>Old Atom</title>'
            . '<entry><title>E</title><link href="https://example.com/e" rel="alternate"/></entry></feed>';

        $feed = $this->parser()->parse($atom);

        self::assertSame('Old Atom', $feed->title);
    }

    public function testUnknownRootStillFailsThroughTheWiredFactory(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('<?xml version="1.0"?><unknown><child/></unknown>');
    }
}
