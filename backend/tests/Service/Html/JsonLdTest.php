<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\JsonLd;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class JsonLdTest extends TestCase
{
    use ParsesHtml;

    public function testFindsOnlyTheScriptsThatCarryJsonLdInDocumentOrder(): void
    {
        $document = $this->document(
            '<html><head><script type="application/ld+json">{"a":1}</script><script>var b = 2;</script>'
            . '<script type="application/json">{"c":3}</script></head>'
            . '<body><script type="application/ld+json">[{"d":4}]</script></body></html>',
        );

        $scripts = iterator_to_array(JsonLd::scriptsIn($document));

        self::assertCount(2, $scripts);
        self::assertSame(['a' => 1], JsonLd::decode($scripts[0]));
        self::assertSame([['d' => 4]], JsonLd::decode($scripts[1]));
    }

    public function testRecognisesAJsonLdScriptOnItsOwn(): void
    {
        $scripts = $this->document('<script type="application/ld+json">{}</script><script>var a = 1;</script>')
            ->getElementsByTagName('script');
        $jsonLd = $scripts->item(0);
        $plain = $scripts->item(1);
        self::assertNotNull($jsonLd);
        self::assertNotNull($plain);

        self::assertTrue(JsonLd::isScript($jsonLd));
        self::assertFalse(JsonLd::isScript($plain));
    }

    public function testABlockThatIsNotAJsonObjectOrArrayDecodesToNothing(): void
    {
        $scripts = iterator_to_array(JsonLd::scriptsIn($this->document(
            '<script type="application/ld+json">{not json</script>'
            . '<script type="application/ld+json">"a string"</script>'
            . '<script type="application/ld+json">42</script>',
        )));

        self::assertSame([[], [], []], array_map(JsonLd::decode(...), $scripts));
    }

    public function testWalksEveryNodeParentFirstInDocumentOrder(): void
    {
        $article = ['@type' => 'Article', 'video' => ['contentUrl' => 'https://x.test/a.mp4']];
        $page = ['@type' => 'WebPage'];
        $block = ['@graph' => [$article, 'a bare string', $page]];

        $nodes = [];
        foreach (JsonLd::nodesIn($block) as $node) {
            $nodes[] = $node;
        }

        self::assertSame([$block, $block['@graph'], $article, $article['video'], $page], $nodes);
    }
}
