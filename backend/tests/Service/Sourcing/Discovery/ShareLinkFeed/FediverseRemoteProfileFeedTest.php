<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\FediverseRemoteProfileFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FediverseRemoteProfileFeedTest extends TestCase
{
    #[DataProvider('remoteProfileLinks')]
    public function testResolvesARemoteProfileToItsHomeInstanceFeed(string $enteredUrl, string $feedUrl): void
    {
        self::assertSame($feedUrl, (new FediverseRemoteProfileFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string, string}> */
    public static function remoteProfileLinks(): iterable
    {
        $linuzifer = 'https://23.social/@linuzifer.rss';

        yield 'the profile' => ['https://systemli.social/@linuzifer@23.social', $linuzifer];
        yield 'a trailing slash' => ['https://systemli.social/@linuzifer@23.social/', $linuzifer];
        yield 'a profile tab' => ['https://systemli.social/@linuzifer@23.social/with_replies', $linuzifer];
        yield 'a post' => ['https://systemli.social/@linuzifer@23.social/115234567890123456', $linuzifer];
        yield 'a query' => ['https://systemli.social/@linuzifer@23.social?lang=de', $linuzifer];
        yield 'a mixed-case home host' => ['https://systemli.social/@linuzifer@23.Social', $linuzifer];
        yield 'a plain http link' => ['http://systemli.social/@linuzifer@23.social', $linuzifer];
        yield 'a user with dots and hyphens keeps its case' => [
            'https://mastodon.social/@Jane.Doe-1@misskey.example.org',
            'https://misskey.example.org/@Jane.Doe-1.rss',
        ];
    }

    #[DataProvider('otherLinks')]
    public function testLeavesEverythingElseAlone(string $enteredUrl): void
    {
        self::assertNull((new FediverseRemoteProfileFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'a local profile' => ['https://23.social/@linuzifer'];
        yield 'a feed already' => ['https://23.social/@linuzifer.rss'];
        yield 'a home host without a dot' => ['https://systemli.social/@linuzifer@localhost'];
        yield 'a home host with an empty label' => ['https://systemli.social/@linuzifer@23..social'];
        yield 'no user' => ['https://systemli.social/@@23.social'];
        yield 'not at the start of the path' => ['https://systemli.social/users/@linuzifer@23.social'];
        yield 'only in the query' => ['https://example.com/search?q=@linuzifer@23.social'];
        yield 'the instance home page' => ['https://systemli.social/'];
        yield 'text that is not a URL' => ['not a url'];
    }
}
