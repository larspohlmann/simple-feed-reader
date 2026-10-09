<?php

declare(strict_types=1);

namespace App\Tests\Service\Html\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\MetaProperty;
use PHPUnit\Framework\TestCase;

final class MetaPropertyTest extends TestCase
{
    public function testReadsTheContentOfTheFirstMatchingProperty(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty(
            '<head><meta property="og:title" content="First"><meta property="og:title" content="Second"></head>',
        );

        self::assertSame('First', MetaProperty::content($document, 'og:title'));
    }

    public function testMatchesThePropertyNameCaseInsensitively(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty('<head><meta property="OG:Title" content="Mixed"></head>');

        self::assertSame('Mixed', MetaProperty::content($document, 'og:title'));
    }

    public function testReadsAnEmptyStringWhenThePropertyIsMissing(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty('<head><meta name="og:title" content="Not a property"></head>');

        self::assertSame('', MetaProperty::content($document, 'og:title'));
    }
}
