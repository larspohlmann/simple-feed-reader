<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\MetaRefreshTarget;
use PHPUnit\Framework\TestCase;

final class MetaRefreshTargetTest extends TestCase
{
    private function meta(string $content): string
    {
        return '<html><head><meta http-equiv="refresh" content="' . $content . '"></head><body>x</body></html>';
    }

    public function testFollowsAZeroDelayAbsoluteTarget(): void
    {
        $target = (new MetaRefreshTarget())
            ->within($this->meta('0; url=https://example.com/real'), 'https://example.com/wall');

        self::assertSame('https://example.com/real', $target);
    }

    public function testResolvesAZeroDelayRelativeTargetAgainstTheLandingUrl(): void
    {
        $target = (new MetaRefreshTarget())
            ->within($this->meta('0; url=/real'), 'https://example.com/wall/here');

        self::assertSame('https://example.com/real', $target);
    }

    public function testLeavesANonZeroDelayReloadAlone(): void
    {
        self::assertNull((new MetaRefreshTarget())
            ->within($this->meta('5; url=https://example.com/real'), 'https://example.com/wall'));
    }

    public function testReturnsNullWhenThereIsNoRefreshMeta(): void
    {
        self::assertNull((new MetaRefreshTarget())
            ->within('<html><body>just an article</body></html>', 'https://example.com/wall'));
    }

    public function testReturnsNullWhenTheRefreshCarriesNoUrl(): void
    {
        self::assertNull((new MetaRefreshTarget())->within($this->meta('0'), 'https://example.com/wall'));
    }

    public function testRejectsANonHttpTarget(): void
    {
        self::assertNull((new MetaRefreshTarget())
            ->within($this->meta('0; url=mailto:editor@example.com'), 'https://example.com/wall'));
        self::assertNull((new MetaRefreshTarget())
            ->within($this->meta("0; url=javascript:alert('x')"), 'https://example.com/wall'));
    }

    public function testAcceptsCapitalisedRefreshAndQuotedUrl(): void
    {
        $html = '<html><head><meta http-equiv="Refresh" content="0; URL=\'https://example.com/real\'">'
            . '</head><body>x</body></html>';

        self::assertSame(
            'https://example.com/real',
            (new MetaRefreshTarget())->within($html, 'https://example.com/wall'),
        );
    }
}
