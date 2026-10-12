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
}
