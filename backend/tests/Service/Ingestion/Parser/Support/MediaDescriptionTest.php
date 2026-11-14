<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\MediaDescription;
use PHPUnit\Framework\TestCase;

final class MediaDescriptionTest extends TestCase
{
    private static function item(string $children): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadXML('<item xmlns:media="http://search.yahoo.com/mrss/">' . $children . '</item>');
        $item = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testAGroupDescriptionIsPlainTextByDefault(): void
    {
        $item = self::item('<media:group><media:description>A &lt;b&gt;
see https://e.example/x</media:description></media:group>');

        self::assertSame(
            '<p>A &lt;b&gt;<br>see <a href="https://e.example/x">https://e.example/x</a></p>',
            MediaDescription::html($item),
        );
    }

    public function testTheItemsOwnDescriptionWinsOverTheGroups(): void
    {
        $item = self::item('<media:description>Own</media:description>'
            . '<media:group><media:description>Group</media:description></media:group>');

        self::assertSame('<p>Own</p>', MediaDescription::html($item));
    }

    public function testAnHtmlTypedDescriptionIsKeptAsMarkup(): void
    {
        $item = self::item('<media:description type="html">&lt;p&gt;Hi&lt;/p&gt;</media:description>');

        self::assertSame('<p>Hi</p>', MediaDescription::html($item));
    }

    public function testNoOrBlankDescriptionIsNull(): void
    {
        self::assertNull(MediaDescription::html(self::item('<title>t</title>')));
        self::assertNull(
            MediaDescription::html(self::item('<media:group><media:description> </media:description></media:group>')),
        );
    }
}
