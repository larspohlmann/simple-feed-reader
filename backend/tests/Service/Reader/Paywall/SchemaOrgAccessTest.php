<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Reader\Paywall\AccessDeclaration;
use App\Service\Reader\Paywall\SchemaOrgAccess;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class SchemaOrgAccessTest extends TestCase
{
    use ParsesHtml;

    public function testABooleanFalseOnTheArticleNodeDeclaresAPaywall(): void
    {
        $html = $this->page('{"@type":"NewsArticle","headline":"x","isAccessibleForFree":false}');

        self::assertSame(AccessDeclaration::Paywalled, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testTheStringFalseUnderHasPartDeclaresAPaywall(): void
    {
        // SZ.de and zeit.de write the value as the string "False".
        $html = $this->page(
            '{"@type":"NewsArticle","hasPart":{"@type":"WebPageElement","cssSelector":".article-content",'
            . '"isAccessibleForFree":"False"}}',
        );

        self::assertSame(AccessDeclaration::Paywalled, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testTheSchemaOrgFalseUrlDeclaresAPaywall(): void
    {
        $html = $this->page('{"@type":"Article","isAccessibleForFree":"http://schema.org/False"}');

        self::assertSame(AccessDeclaration::Paywalled, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testATrueWithNoFalseDeclaresFreeAccess(): void
    {
        $html = $this->page('{"@type":"Article","isAccessibleForFree":true}');

        self::assertSame(AccessDeclaration::Free, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testAFalseInAnyBlockWinsOverATrueInAnother(): void
    {
        $html = $this->page('{"@type":"WebPage","isAccessibleForFree":true}')
            . $this->page('{"@type":"Article","isAccessibleForFree":false}');

        self::assertSame(AccessDeclaration::Paywalled, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testAPageWithoutTheKeyDeclaresNothing(): void
    {
        $html = $this->page('{"@type":"Article","headline":"Free as in beer"}');

        self::assertSame(AccessDeclaration::Undeclared, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testAGraphIsWalkedToTheNestedNode(): void
    {
        $html = $this->page('{"@graph":[{"@type":"WebSite"},{"@type":"Article","isAccessibleForFree":false}]}');

        self::assertSame(AccessDeclaration::Paywalled, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testAnUnparseableBlockIsSkippedAndTheNextOneDecides(): void
    {
        $html = $this->page('{not json')
            . $this->page('{"@type":"Article","isAccessibleForFree":false}');

        self::assertSame(AccessDeclaration::Paywalled, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testAnOrdinaryScriptIsNotReadAsJsonLd(): void
    {
        $html = '<script>{"@type":"Article","isAccessibleForFree":false}</script>';

        self::assertSame(AccessDeclaration::Undeclared, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testReadsOnlyTheScriptsTheJsonLdSelectorMatches(): void
    {
        $html = '<html><head><script type="application/ld+json; charset=utf-8">{"isAccessibleForFree":false}'
            . '</script></head><body></body></html>';

        self::assertSame(AccessDeclaration::Undeclared, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    public function testAnUnknownStringValueDeclaresNothing(): void
    {
        $html = $this->page('{"@type":"Article","isAccessibleForFree":"maybe"}');

        self::assertSame(AccessDeclaration::Undeclared, SchemaOrgAccess::declaredIn($this->document($html)));
    }

    private function page(string $jsonLd): string
    {
        return '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body></body></html>';
    }
}
