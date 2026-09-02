<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\Sibling\KeyedOccurrences;
use PHPUnit\Framework\TestCase;

final class KeyedOccurrencesTest extends TestCase
{
    /** The Next.js flight payload escapes its quotes; the key on either side is still readable. */
    public function testReadsAnEscapedJsonValueWithItsNeighbouringKeys(): void
    {
        $html = '<script>self.__next_f.push([1,"{\\"config\\":{\\"isPriority\\":\\"$undefined\\",'
            . '\\"content\\":\\"taktik-analyse-video-100\\",\\"startImage\\":{\\"title\\":\\"x\\"}}}"])</script>';

        $found = KeyedOccurrences::of($html, 'taktik-analyse-video-100');

        self::assertCount(1, $found);
        self::assertSame('content', $found[0]->key);
        self::assertSame('isPriority', $found[0]->previousKey);
        self::assertSame('startImage', $found[0]->nextKey);
        self::assertSame(strpos($html, 'taktik-analyse-video-100'), $found[0]->position);
    }

    public function testReadsAPlainJsonValue(): void
    {
        $html = '<script type="application/json">{"kind":"clip","id":"clip-77","poster":"p.jpg"}</script>';

        $found = KeyedOccurrences::of($html, 'clip-77');

        self::assertSame(['kind', 'id', 'poster'], [$found[0]->previousKey, $found[0]->key, $found[0]->nextKey]);
    }

    public function testReadsAnAttributeValue(): void
    {
        $html = '<div class="player" data-content="clip-77" data-poster="p.jpg"></div>';

        $found = KeyedOccurrences::of($html, 'clip-77');

        $keys = [$found[0]->previousKey, $found[0]->key, $found[0]->nextKey];
        self::assertSame(['class', 'data-content', 'data-poster'], $keys);
    }

    public function testAnOccurrenceInsideAUrlPathHasNoKey(): void
    {
        $html = '<a href="/video/xpress/clip-77.html">x</a> {"contentUrl":"https://a.test/api/video/clip-77.m3u8"}';

        self::assertSame([], KeyedOccurrences::of($html, 'clip-77'));
    }

    public function testAtReadsOneOccurrenceByPosition(): void
    {
        $html = '{"a":"clip-77","b":1} {"x":"clip-77","y":2}';
        $second = strrpos($html, 'clip-77');
        self::assertIsInt($second);

        $occurrence = KeyedOccurrences::at($html, 'clip-77', $second);

        self::assertNotNull($occurrence);
        self::assertSame('x', $occurrence->key);
        self::assertSame('y', $occurrence->nextKey);
    }

    public function testContextIsTheKeyAndBothNeighbours(): void
    {
        $html = '{"p":1,"k":"one-1","n":2} {"p":1,"k":"two-2","n":2} {"q":1,"k":"three-3","n":2}';
        $one = KeyedOccurrences::of($html, 'one-1')[0];

        self::assertTrue($one->sharesContextWith(KeyedOccurrences::of($html, 'two-2')[0]));
        self::assertFalse($one->sharesContextWith(KeyedOccurrences::of($html, 'three-3')[0]));
    }
}
