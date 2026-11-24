<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Subscription\Support;

use App\Service\Library\Subscription\Support\SubscribableUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscribableUrlTest extends TestCase
{
    #[DataProvider('subscribableUrls')]
    public function testAWebUrlWithAHostIsSubscribable(string $url): void
    {
        self::assertTrue(SubscribableUrl::isSubscribable($url));
    }

    #[DataProvider('unsubscribableUrls')]
    public function testAnythingElseIsNot(string $url): void
    {
        self::assertFalse(SubscribableUrl::isSubscribable($url));
    }

    /** @return iterable<string, array{string}> */
    public static function subscribableUrls(): iterable
    {
        yield 'https' => ['https://a.example/feed'];
        yield 'http without a path' => ['http://a.example'];
        yield 'exactly 750 characters' => [self::paddedTo(750)];
    }

    /** @return iterable<string, array{string}> */
    public static function unsubscribableUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'ftp' => ['ftp://a.example/feed'];
        yield 'no host' => ['https:///feed'];
        yield 'no scheme' => ['a.example/feed'];
        yield '751 characters' => [self::paddedTo(751)];
    }

    private static function paddedTo(int $length): string
    {
        return str_pad('https://a.example/', $length, 'a');
    }
}
