<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Url\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new UrlNormalizer();
    }

    public function testStripsBbcTrackingParametersThatVaryPerFetch(): void
    {
        self::assertSame(
            'https://www.bbc.com/news/articles/ckg4424zd7go',
            $this->normalizer->normalize(
                'https://www.bbc.com/news/articles/ckg4424zd7go?at_medium=RSS&at_campaign=rss',
            ),
        );
    }

    public function testStripsUtmParameters(): void
    {
        self::assertSame(
            'https://example.com/story',
            $this->normalizer->normalize('https://example.com/story?utm_source=feed&utm_medium=rss'),
        );
    }

    public function testStripsFbclidAndGclid(): void
    {
        self::assertSame(
            'https://example.com/story',
            $this->normalizer->normalize('https://example.com/story?fbclid=abc&gclid=xyz'),
        );
    }

    public function testKeepsNonTrackingQueryParametersThatIdentifyTheArticle(): void
    {
        self::assertSame(
            'https://example.com/story?id=42&page=2',
            $this->normalizer->normalize('https://example.com/story?id=42&utm_source=feed&page=2'),
        );
    }

    public function testRemovesTheFragment(): void
    {
        self::assertSame(
            'https://example.com/story',
            $this->normalizer->normalize('https://example.com/story#section-3'),
        );
    }

    public function testLowercasesSchemeAndHostButNotThePath(): void
    {
        self::assertSame(
            'https://example.com/Story-Path',
            $this->normalizer->normalize('HTTPS://Example.COM/Story-Path'),
        );
    }

    public function testDropsTheDefaultPort(): void
    {
        self::assertSame(
            'https://example.com/story',
            $this->normalizer->normalize('https://example.com:443/story'),
        );
    }

    public function testKeepsANonDefaultPort(): void
    {
        self::assertSame(
            'https://example.com:8443/story',
            $this->normalizer->normalize('https://example.com:8443/story'),
        );
    }

    public function testKeepsATrailingSlashSoTwoDistinctPathsDoNotCollapse(): void
    {
        self::assertSame(
            'https://example.com/story/',
            $this->normalizer->normalize('https://example.com/story/'),
        );
    }

    public function testReturnsNullForNull(): void
    {
        self::assertNull($this->normalizer->normalize(null));
    }

    public function testReturnsNullForAnEmptyString(): void
    {
        self::assertNull($this->normalizer->normalize(''));
    }

    public function testReturnsNullForAUrlWithoutAHost(): void
    {
        self::assertNull($this->normalizer->normalize('not a url'));
    }
}
