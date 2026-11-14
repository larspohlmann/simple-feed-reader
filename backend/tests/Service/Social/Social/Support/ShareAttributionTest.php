<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\ShareAttribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShareAttributionTest extends TestCase
{
    #[DataProvider('sharers')]
    public function testNamesTheSharerAndLinksOnlyAWebPage(?string $name, ?string $profileUrl, string $expected): void
    {
        self::assertSame($expected, ShareAttribution::line('Boosted by', $name, $profileUrl));
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function sharers(): iterable
    {
        yield 'name and page' => [
            'Carol <3',
            'https://mastodon.example/@carol',
            '<p>Boosted by <a href="https://mastodon.example/@carol">Carol &lt;3</a></p>',
        ];
        yield 'name only' => ['Carol <3', null, '<p>Boosted by Carol &lt;3</p>'];
        yield 'no name' => [null, 'https://mastodon.example/@carol', ''];
    }
}
