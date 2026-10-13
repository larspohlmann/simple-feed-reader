<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\MastodonStatusAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonStatusAddressTest extends TestCase
{
    #[DataProvider('statusAddresses')]
    public function testAStatusAddressNamesItsOriginsPublicStatus(string $address): void
    {
        self::assertSame(
            'https://mastodon.social/api/v1/statuses/110',
            MastodonStatusAddress::statusApiUrlOrNull($address),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function statusAddresses(): iterable
    {
        yield 'profile shape' => ['https://mastodon.social/@Gargron/110'];
        yield 'users shape' => ['https://mastodon.social/users/Gargron/statuses/110'];
    }

    #[DataProvider('otherAddresses')]
    public function testAnyOtherAddressNamesNoStatus(string $address): void
    {
        self::assertNull(MastodonStatusAddress::statusApiUrlOrNull($address));
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
}
