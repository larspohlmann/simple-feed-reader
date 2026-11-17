<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\Support;

use App\Service\Outbound\Fetch\Support\LemmyActorPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyActorPageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function urls(): iterable
    {
        yield 'a community page' => [
            'https://lemmy.world/c/simplefeedreader',
            'https://lemmy.world/c/simplefeedreader',
        ];
        yield 'an http page with a trailing slash' => [
            'http://lemmy.world/c/simplefeedreader/',
            'https://lemmy.world/c/simplefeedreader',
        ];
        yield 'a community feed with a query' => [
            'https://lemmy.world/feeds/c/technology.xml?sort=New',
            'https://lemmy.world/c/technology',
        ];
        yield 'a user page' => ['https://lemmy.world/u/lars_pohlmann', 'https://lemmy.world/u/lars_pohlmann'];
        yield 'a user feed' => [
            'https://lemmy.world/feeds/u/lars_pohlmann.xml',
            'https://lemmy.world/u/lars_pohlmann',
        ];
        yield 'a remote community' => ['https://feddit.org/c/linux@lemmy.ml', 'https://feddit.org/c/linux@lemmy.ml'];
        yield 'the root' => ['https://lemmy.world/', null];
        yield 'an empty community name' => ['https://lemmy.world/c/', null];
        yield 'a deeper path' => ['https://lemmy.world/c/a/b', null];
        yield 'the local feed' => ['https://lemmy.world/feeds/local.xml', null];
        yield 'another prefix' => ['https://lemmy.world/m/technology', null];
        yield 'no url' => ['not a url', null];
    }

    #[DataProvider('urls')]
    public function testItNamesTheActorPage(string $url, ?string $expected): void
    {
        self::assertSame($expected, LemmyActorPage::of($url));
    }
}
