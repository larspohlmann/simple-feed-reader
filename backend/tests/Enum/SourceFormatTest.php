<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SourceFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceFormatTest extends TestCase
{
    #[DataProvider('formats')]
    public function testOnlyTheTwoNetworkApisAreSocial(string $sourceFormat, bool $social): void
    {
        self::assertSame($social, SourceFormat::isSocial($sourceFormat));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function formats(): iterable
    {
        yield 'bluesky' => [SourceFormat::BLUESKY_API, true];
        yield 'mastodon' => [SourceFormat::MASTODON_API, true];
        yield 'xml' => [SourceFormat::XML, false];
        yield 'scraped' => [SourceFormat::SCRAPED, false];
        yield 'wp-json' => [SourceFormat::WP_JSON, false];
        yield 'unknown' => ['bluesky', false];
    }

    #[DataProvider('verbatimFormats')]
    public function testOnlyTheOfferedSourcesAreVerbatim(?string $sourceFormat, bool $verbatim): void
    {
        self::assertSame($verbatim, SourceFormat::isVerbatim($sourceFormat));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function verbatimFormats(): iterable
    {
        yield 'scraped' => [SourceFormat::SCRAPED, true];
        yield 'wp-json' => [SourceFormat::WP_JSON, true];
        yield 'mixcloud' => [SourceFormat::MIXCLOUD_API, true];
        yield 'xml' => [SourceFormat::XML, false];
        yield 'bluesky' => [SourceFormat::BLUESKY_API, false];
        yield 'a discovery syntax' => ['rss', false];
        yield 'none' => [null, false];
    }

    #[DataProvider('feedLanguageFormats')]
    public function testOnlyAFeedOfOneSourceCarriesALanguage(?string $sourceFormat, bool $carries): void
    {
        self::assertSame($carries, SourceFormat::carriesFeedLanguage($sourceFormat));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function feedLanguageFormats(): iterable
    {
        yield 'xml' => [SourceFormat::XML, true];
        yield 'scraped' => [SourceFormat::SCRAPED, true];
        yield 'wp-json' => [SourceFormat::WP_JSON, true];
        yield 'mixcloud' => [SourceFormat::MIXCLOUD_API, true];
        yield 'none' => [null, true];
        yield 'bluesky' => [SourceFormat::BLUESKY_API, false];
        yield 'mastodon' => [SourceFormat::MASTODON_API, false];
        yield 'lemmy' => [SourceFormat::LEMMY_API, false];
        yield 'saved' => [SourceFormat::SAVED, false];
    }
}
