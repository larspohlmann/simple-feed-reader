<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SearchEngineCapability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchEngineCapabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideUrls(): iterable
    {
        yield 'empty means unconfigured' => ['', false];
        yield 'a real url means configured' => ['http://meilisearch.test:7700', true];
        // A .env.local hand-edit (this project's Strato deploy) can leave a
        // whitespace-only value behind — that must read exactly like empty,
        // never like a real, if malformed, address.
        yield 'whitespace-only means unconfigured' => ['   ', false];
        yield 'whitespace-padded means configured' => [' http://meilisearch.test:7700 ', true];
    }

    #[DataProvider('provideUrls')]
    public function testIsConfiguredReflectsTheTrimmedUrl(string $url, bool $expectedConfigured): void
    {
        self::assertSame($expectedConfigured, (new SearchEngineCapability($url, ''))->isConfigured());
    }

    public function testUrlIsTrimmed(): void
    {
        $capability = new SearchEngineCapability(' http://meilisearch.test:7700 ', '');

        self::assertSame('http://meilisearch.test:7700', $capability->url());
    }

    /**
     * isConfigured() and url() must never be able to disagree — both are
     * derived from the same trimmed value, not from two separate reads of
     * the raw env var.
     */
    public function testAWhitespaceOnlyUrlIsEmptyBothForIsConfiguredAndForUrl(): void
    {
        $capability = new SearchEngineCapability('   ', '');

        self::assertFalse($capability->isConfigured());
        self::assertSame('', $capability->url());
    }

    public function testKeyIsTrimmed(): void
    {
        $capability = new SearchEngineCapability('http://meilisearch.test:7700', ' a-master-key ');

        self::assertSame('a-master-key', $capability->key());
    }
}
