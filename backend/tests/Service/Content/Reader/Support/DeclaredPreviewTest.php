<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Reader\Support;

use App\Entity\LinkCardContent;
use App\Enum\FeedLanguage;
use App\Service\Content\Reader\Model\PagePreviewModel;
use App\Service\Content\Reader\Support\DeclaredPreview;
use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeclaredPreviewTest extends TestCase
{
    private const string SHARED = 'https://example.com/story';

    #[DataProvider('htmlLanguages')]
    public function testHtmlLangStatesTheLanguage(string $tag, FeedLanguage $expected): void
    {
        self::assertSame($expected, self::preview('<html lang="' . $tag . '"><head><title>T</title></head>')->language);
    }

    /** @return iterable<string, array{string, FeedLanguage}> */
    public static function htmlLanguages(): iterable
    {
        yield 'German, regional' => ['de-DE', FeedLanguage::German];
        yield 'French' => ['fr', FeedLanguage::French];
        yield 'Spanish, Latin America' => ['es-419', FeedLanguage::Spanish];
        yield 'English, British' => ['en-GB', FeedLanguage::English];
    }

    public function testOgLocaleIsTheFallback(): void
    {
        $preview = self::preview('<html><head><meta property="og:locale" content="fr_FR"><title>T</title></head>');

        self::assertSame(FeedLanguage::French, $preview->language);
    }

    public function testABlankHtmlLangFallsBackToOgLocale(): void
    {
        $preview = self::preview(
            '<html lang="  "><head><meta property="og:locale" content="es_ES"><title>T</title></head>',
        );

        self::assertSame(FeedLanguage::Spanish, $preview->language);
    }

    public function testHtmlLangOutranksOgLocale(): void
    {
        $preview = self::preview(
            '<html lang="es"><head><meta property="og:locale" content="fr_FR"><title>T</title></head>',
        );

        self::assertSame(FeedLanguage::Spanish, $preview->language);
    }

    public function testATagOutsideTheFourStatesNone(): void
    {
        self::assertNull(self::preview('<html lang="it"><head><title>T</title></head>')->language);
        self::assertNull(self::preview('<html><head><title>T</title></head>')->language);
    }

    public function testTheCardFieldsAreThoseOfThePageCardReader(): void
    {
        $preview = self::preview(
            '<html><head><title>Element title</title><meta property="og:title" content="Headline">'
            . '<meta name="description" content="Summary"><meta property="og:image" content="/i.jpg">'
            . '<meta property="og:site_name" content="Example News"></head>',
            'https://cdn.example.org/landing',
        );

        self::assertEquals(
            new LinkCardContent(self::SHARED, 'Headline', 'Summary', 'https://cdn.example.org/i.jpg', 'Example News'),
            $preview->card(),
        );
    }

    public function testTheTitleElementAndTheHostAreTheFallbacks(): void
    {
        $preview = self::preview('<html><head><title> Element   title </title></head>');

        self::assertEquals(
            new LinkCardContent(self::SHARED, 'Element title', null, null, 'example.com'),
            $preview->card(),
        );
    }

    public function testAPageWithoutASiteNameIsNamedAfterTheHostItLandedOn(): void
    {
        $preview = self::preview('<html><head><title>T</title></head>', 'https://www.example.org/landing');

        self::assertSame('example.org', $preview->card()->siteName);
    }

    private static function preview(string $head, string $finalUrl = self::SHARED): PagePreviewModel
    {
        return DeclaredPreview::fromDocument(HtmlDocumentParser::parseOrEmpty($head), self::SHARED, $finalUrl);
    }
}
