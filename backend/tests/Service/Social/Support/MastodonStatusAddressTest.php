<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\MastodonStatusAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonStatusAddressTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function statusAddresses(): iterable
    {
        yield 'profile shape' => ['https://mastodon.social/@Gargron/110'];
        yield 'users shape' => ['https://mastodon.social/users/Gargron/statuses/110'];
    }

    /** @return iterable<string, array{string}> */
    public static function otherAddresses(): iterable
    {
        yield 'plain http' => ['http://mastodon.social/@Gargron/110'];
        yield 'a page under the status' => ['https://mastodon.social/@Gargron/110/reblogs'];
        yield 'a non-numeric id' => ['https://mastodon.social/@Gargron/abc'];
        yield 'a query' => ['https://mastodon.social/@Gargron/110?x=1'];
        yield 'credentials before the host' => ['https://user@evil.example/@Gargron/110'];
        yield 'pixelfed' => ['https://pixelfed.social/p/alice/123'];
        yield 'misskey' => ['https://misskey.io/notes/9abcdef'];
    }

    #[DataProvider('statusAddresses')]
    public function testAStatusAddressNamesItsStatusAndContextEndpoint(string $address): void
    {
        $status = MastodonStatusAddress::ofOrNull($address);

        self::assertNotNull($status);
        self::assertSame('110', $status->id);
        self::assertSame('https://mastodon.social/api/v1/statuses/110', $status->apiUrl());
        self::assertSame('https://mastodon.social/api/v1/statuses/110/context', $status->contextApiUrl());
    }

    #[DataProvider('otherAddresses')]
    public function testAnyOtherAddressIsNoStatus(string $address): void
    {
        self::assertNull(MastodonStatusAddress::ofOrNull($address));
    }

    #[DataProvider('authorPrefixes')]
    public function testAStatusAddressNamesItsAuthorsProfilePrefix(string $address, string $prefix): void
    {
        self::assertSame($prefix, MastodonStatusAddress::ofOrNull($address)?->authorPrefix);
    }

    /** @return iterable<string, array{string, string}> */
    public static function authorPrefixes(): iterable
    {
        yield 'profile shape' => ['https://mastodon.social/@Gargron/110', 'https://mastodon.social/@Gargron'];
        yield 'users shape' => [
            'https://mastodon.social/users/Gargron/statuses/110',
            'https://mastodon.social/users/Gargron',
        ];
    }
}
