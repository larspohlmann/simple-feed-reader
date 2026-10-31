<?php

declare(strict_types=1);

namespace App\Tests\Service\Url\Support;

use App\Service\Url\Support\MixcloudUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudUrlTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function profilePages(): iterable
    {
        yield 'a profile' => ['https://www.mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'without the trailing slash' => ['https://www.mixcloud.com/johndigweed', 'johndigweed'];
        yield 'with a query' => ['https://www.mixcloud.com/johndigweed/?utm_source=x', 'johndigweed'];
        yield 'capitals kept' => ['https://www.mixcloud.com/NTSRadio/', 'NTSRadio'];
        yield 'the bare host' => ['https://mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'a capitalised site section' => ['https://www.mixcloud.com/Discover/', null];
        yield 'a mixed-case host' => ['https://www.MixCloud.com/johndigweed/', 'johndigweed'];
        yield 'a show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/', null];
        yield 'a site section' => ['https://www.mixcloud.com/discover/', null];
        yield 'the upload page' => ['https://www.mixcloud.com/upload/', null];
        yield 'the select page' => ['https://www.mixcloud.com/select/', null];
        yield 'the home page' => ['https://www.mixcloud.com/', null];
        yield 'another host' => ['https://soundcloud.com/johndigweed', null];
        yield 'a look-alike host' => ['https://www.mixcloud.com.evil.test/johndigweed/', null];
        yield 'the API host' => ['https://api.mixcloud.com/johndigweed/', null];
    }

    #[DataProvider('profilePages')]
    public function testReadsTheUserOfAProfilePage(string $url, ?string $user): void
    {
        self::assertSame($user, MixcloudUrl::profileUser($url));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cloudcastsUrls(): iterable
    {
        yield 'a cloudcasts list' => ['https://api.mixcloud.com/johndigweed/cloudcasts/', true];
        yield 'without the trailing slash' => ['https://api.mixcloud.com/johndigweed/cloudcasts', true];
        yield 'with a query' => ['https://api.mixcloud.com/johndigweed/cloudcasts/?limit=20', true];
        yield 'a mixed-case host' => ['https://API.Mixcloud.com/johndigweed/cloudcasts/', true];
        yield 'an encoded user' => ['https://api.mixcloud.com/caf%C3%A9/cloudcasts/', true];
        yield 'the user itself' => ['https://api.mixcloud.com/johndigweed/', false];
        yield 'another list' => ['https://api.mixcloud.com/johndigweed/favorites/', false];
        yield 'a show' => ['https://api.mixcloud.com/johndigweed/transitions-1154/', false];
        yield 'a deeper path' => ['https://api.mixcloud.com/johndigweed/cloudcasts/extra/', false];
        yield 'the site host' => ['https://www.mixcloud.com/johndigweed/cloudcasts/', false];
        yield 'a look-alike host' => ['https://api.mixcloud.com.evil.test/johndigweed/cloudcasts/', false];
    }

    #[DataProvider('cloudcastsUrls')]
    public function testTellsACloudcastsListFromAnythingElse(string $url, bool $isCloudcasts): void
    {
        self::assertSame($isCloudcasts, MixcloudUrl::isCloudcastsUrl($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function showPages(): iterable
    {
        yield 'a show' => [
            'https://www.mixcloud.com/johndigweed/transitions-1154/',
            '/johndigweed/transitions-1154/',
        ];
        yield 'without the trailing slash, with a fragment' => [
            'https://www.mixcloud.com/johndigweed/transitions-1154#comments',
            '/johndigweed/transitions-1154/',
        ];
        yield 'an encoded non-ASCII slug, kept encoded' => [
            'https://www.mixcloud.com/worldwidefm/gilles-peterson-w-moses-boyd-flo-real-mel%C3%A9-29-10-26/',
            '/worldwidefm/gilles-peterson-w-moses-boyd-flo-real-mel%C3%A9-29-10-26/',
        ];
        yield 'a slug ending in an underscore' => [
            'https://www.mixcloud.com/johndigweed/transitions-with-john-digweed-and-tim-green-_/',
            '/johndigweed/transitions-with-john-digweed-and-tim-green-_/',
        ];
        yield 'a profile' => ['https://www.mixcloud.com/johndigweed/', null];
        yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/uploads/', null];
        yield 'a genre' => ['https://www.mixcloud.com/genres/techno/', null];
        yield 'an old widget' => ['https://www.mixcloud.com/widget/iframe/?feed=x', null];
        yield 'three segments' => ['https://www.mixcloud.com/johndigweed/transitions-1154/extra/', null];
        yield 'a raw non-ASCII slug' => ['https://www.mixcloud.com/worldwidefm/melé/', null];
        yield 'another host' => ['https://example.com/johndigweed/transitions-1154/', null];
    }

    #[DataProvider('showPages')]
    public function testReadsTheKeyOfAShowPage(string $url, ?string $key): void
    {
        self::assertSame($key, MixcloudUrl::showKey($url));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function keys(): iterable
    {
        yield 'a show' => ['/johndigweed/transitions-1154/', true];
        yield 'an encoded slug' => ['/NTSRadio/ma%C3%AF-linh-invites-tasya-kudryk-29th-october-2026/', true];
        yield 'no trailing slash' => ['/johndigweed/transitions-1154', false];
        yield 'a profile' => ['/johndigweed/', false];
        yield 'a profile tab' => ['/johndigweed/favorites/', false];
        yield 'a capitalised profile tab' => ['/johndigweed/Uploads/', false];
        yield 'a capitalised site section' => ['/Discover/house/', false];
        yield 'a site section' => ['/discover/house/', false];
        yield 'a full url' => ['https://www.mixcloud.com/johndigweed/transitions-1154/', false];
        yield 'a space' => ['/johndigweed/two words/', false];
    }

    #[DataProvider('keys')]
    public function testTellsAShowKeyFromAnythingElse(string $key, bool $isShowKey): void
    {
        self::assertSame($isShowKey, MixcloudUrl::isShowKey($key));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function showPageUsers(): iterable
    {
        yield 'a show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/', 'johndigweed'];
        yield 'an encoded show' => ['https://www.mixcloud.com/worldwidefm/caf%C3%A9-show/', 'worldwidefm'];
        yield 'a profile page' => ['https://www.mixcloud.com/johndigweed/', null];
        yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/favorites/', null];
        yield 'a site section' => ['https://www.mixcloud.com/discover/house/', null];
        yield 'the API host' => ['https://api.mixcloud.com/johndigweed/transitions-1154/', null];
    }

    #[DataProvider('showPageUsers')]
    public function testReadsTheUserOfAShowPage(string $url, ?string $user): void
    {
        self::assertSame($user, MixcloudUrl::showUser($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function apiUsers(): iterable
    {
        yield 'a cloudcasts list' => ['https://api.mixcloud.com/johndigweed/cloudcasts/', 'johndigweed'];
        yield 'a list without the slash' => ['https://api.mixcloud.com/johndigweed/cloudcasts', 'johndigweed'];
        yield 'a user' => ['https://api.mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'a user without the slash' => ['https://api.mixcloud.com/johndigweed', 'johndigweed'];
        yield 'an encoded user' => ['https://api.mixcloud.com/caf%C3%A9/', 'caf%C3%A9'];
        yield 'with a query' => ['https://api.mixcloud.com/johndigweed/cloudcasts/?limit=5', 'johndigweed'];
        yield 'a mixed-case host' => ['https://API.Mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'another list' => ['https://api.mixcloud.com/johndigweed/favorites/', null];
        yield 'a show' => ['https://api.mixcloud.com/johndigweed/transitions-1154/', null];
        yield 'a site section' => ['https://api.mixcloud.com/discover/', null];
        yield 'the API root' => ['https://api.mixcloud.com/', null];
        yield 'the site host' => ['https://www.mixcloud.com/johndigweed/', null];
        yield 'a look-alike host' => ['https://api.mixcloud.com.evil.test/johndigweed/', null];
    }

    #[DataProvider('apiUsers')]
    public function testReadsTheUserOfAnApiAddress(string $url, ?string $user): void
    {
        self::assertSame($user, MixcloudUrl::apiUser($url));
    }
}
