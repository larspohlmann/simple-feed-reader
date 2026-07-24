<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\Layer\JsonLdLayer;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class JsonLdLayerTest extends TestCase
{
    use ScrapedFixtures;

    /** @return list<\App\Service\Scraper\ScrapedItem> */
    private function extract(string $fixture): array
    {
        $doc = HTMLDocument::createFromString($this->scrapedFixture($fixture), \LIBXML_NOERROR);

        return new JsonLdLayer()->extract($doc, 'https://news.test/section/');
    }

    public function testExtractsItemListWithFields(): void
    {
        $items = $this->extract('jsonld-list.html');
        self::assertCount(4, $items);
        self::assertSame('https://news.test/story-1', $items[0]->url);
        self::assertNotNull($items[0]->teaser);
        self::assertNotNull($items[0]->imageUrl);
        self::assertSame('2026-07-20', $items[0]->publishedAt?->format('Y-m-d'));
    }

    public function testIgnoresPagesWithoutArticleStructures(): void
    {
        $doc = HTMLDocument::createFromString(
            '<html lang="en"><body><script type="application/ld+json">{"@type":"Organization","name":"X"}</script>'
            . '</body></html>',
            \LIBXML_NOERROR
        );
        self::assertSame([], new JsonLdLayer()->extract($doc, 'https://news.test/'));
    }

    /**
     * heise.de ships teasers in schema.org "abstract" rather than
     * "description", mixes bare string entries into itemListElement, and
     * references some items by URL string — the former must map, the latter
     * two must be skipped without extracting the ListItem wrapper instead.
     */
    public function testAbstractTeaserFallbackAndNonArrayEntriesAreSkipped(): void
    {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => [
                'https://news.test/bare-string-entry',
                [
                    '@type' => 'ListItem',
                    'url' => 'https://news.test/wrapper-url',
                    'name' => 'Wrapper name that must not be extracted',
                    'item' => 'https://news.test/item-as-string',
                ],
                [
                    '@type' => 'ListItem',
                    'item' => [
                        '@type' => 'NewsArticle',
                        'url' => '/abstract-story',
                        'headline' => 'Story with an abstract instead of a description',
                        'abstract' => 'Teaser text delivered via the schema.org abstract property,'
                            . ' well over forty characters.',
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        $doc = HTMLDocument::createFromString(
            '<html lang="en"><body><script type="application/ld+json">' . $json . '</script></body></html>',
            \LIBXML_NOERROR
        );

        $items = new JsonLdLayer()->extract($doc, 'https://news.test/');

        self::assertCount(1, $items);
        self::assertSame('https://news.test/abstract-story', $items[0]->url);
        self::assertStringContainsString('abstract property', (string) $items[0]->teaser);
    }

    /**
     * A pathological @graph of many valid Article nodes must not force
     * unbounded work: collection stops at MAX_COLLECT (200), yielding the
     * first 200 nodes in document order. The facade caps the final output at
     * 50 downstream, so real pages are unaffected. Completes near-instantly.
     */
    public function testGraphCollectionIsBoundedAtMaxCollect(): void
    {
        $nodes = [];
        for ($i = 0; $i < 500; ++$i) {
            $nodes[] = [
                '@type' => 'Article',
                'url' => '/a/' . $i,
                'headline' => 'Headline number ' . $i,
                'description' => 'A description for article number ' . $i
                    . ' that is comfortably over forty characters long.',
            ];
        }
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => $nodes,
        ], \JSON_THROW_ON_ERROR);
        $doc = HTMLDocument::createFromString(
            '<html lang="en"><body><script type="application/ld+json">' . $json . '</script></body></html>',
            \LIBXML_NOERROR
        );

        $items = new JsonLdLayer()->extract($doc, 'https://x.test/');

        self::assertCount(200, $items);
        self::assertSame('https://x.test/a/0', $items[0]->url);
        self::assertSame('https://x.test/a/199', $items[199]->url);
    }
}
