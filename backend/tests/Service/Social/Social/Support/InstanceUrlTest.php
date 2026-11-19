<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Social\Support\InstanceUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstanceUrlTest extends TestCase
{
    public function testAnInstanceUrlTypedWithSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('https://mastodon.social', InstanceUrl::requireOrigin("  https://mastodon.social \n"));
    }

    public function testAnUppercaseSchemeIsAcceptedAsHttps(): void
    {
        self::assertSame('https://mastodon.social', InstanceUrl::requireOrigin('HTTPS://mastodon.social'));
    }

    public function testAnInstanceUrlWithoutAHostNamesNoInstance(): void
    {
        $this->expectException(InvalidInstanceUrlException::class);
        $this->expectExceptionMessage('names no instance');

        InstanceUrl::requireOrigin('https://');
    }

    public function testAnInstanceUrlWithOnlyAUserOrOnlyAPasswordIsRefused(): void
    {
        foreach (['https://user@mastodon.social', 'https://:pass@mastodon.social'] as $typed) {
            try {
                InstanceUrl::requireOrigin($typed);
                self::fail('Expected InvalidInstanceUrlException for ' . $typed);
            } catch (InvalidInstanceUrlException $exception) {
                self::assertStringContainsString('credentials', $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function instanceUrls(): iterable
    {
        yield 'bare host' => ['mastodon.social', 'https://mastodon.social'];
        yield 'trailing slash and path' => ['https://Mastodon.Social/@lars/', 'https://mastodon.social'];
        yield 'port kept' => ['https://social.example.test:8443', 'https://social.example.test:8443'];
    }

    #[DataProvider('instanceUrls')]
    public function testAMastodonInstanceUrlIsNormalised(string $typed, string $expected): void
    {
        self::assertSame($expected, InstanceUrl::requireOrigin($typed));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedInstanceUrls(): iterable
    {
        yield 'plain http' => ['http://mastodon.social'];
        yield 'credentials' => ['https://user:pass@mastodon.social'];
        yield 'no host' => ['https://'];
    }

    #[DataProvider('refusedInstanceUrls')]
    public function testAnUnsafeInstanceUrlIsRefused(string $typed): void
    {
        $this->expectException(InvalidInstanceUrlException::class);

        InstanceUrl::requireOrigin($typed);
    }
}
