<?php

declare(strict_types=1);

namespace App\Tests\Service\Html\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\MetaTags;
use PHPUnit\Framework\TestCase;

final class MetaTagsTest extends TestCase
{
    public function testReadsEachPropertyOrNameOnceTheFirstWinning(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty(
            '<head><meta property="og:title" content="First"><meta name="og:title" content="Second">'
            . '<meta name="description" content="About"><meta charset="utf-8"></head>',
        );

        self::assertSame(['og:title' => 'First', 'description' => 'About'], MetaTags::contentByKey($document));
    }

    public function testKeysAreLowerCased(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty('<head><meta property="OG:Title" content="Mixed"></head>');

        self::assertSame(['og:title' => 'Mixed'], MetaTags::contentByKey($document));
    }

    public function testAPropertyWinsOverANameOnTheSameTag(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty(
            '<head><meta property="og:image" name="twitter:image" content="https://a.test/i.jpg"></head>',
        );

        self::assertSame(['og:image' => 'https://a.test/i.jpg'], MetaTags::contentByKey($document));
    }
}
