<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\OwnInstancePostUrl;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class OwnInstancePostUrlTest extends TestCase
{
    private const string POST = 'https://mastodon.social/@Gargron/109';

    public function testAForeignPostOpensThroughTheReadersInstance(): void
    {
        self::assertSame(
            'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40Gargron%2F109',
            OwnInstancePostUrl::among([SocialAccounts::mastodonOn('https://home.example')], self::POST),
        );
    }

    public function testATrailingSlashOnTheInstanceIsNotDoubled(): void
    {
        self::assertStringStartsWith(
            'https://home.example/authorize_interaction?uri=',
            (string) OwnInstancePostUrl::among([SocialAccounts::mastodonOn('https://home.example/')], self::POST),
        );
    }

    public function testAPostOnTheReadersOwnInstanceKeepsItsPlainLink(): void
    {
        self::assertNull(
            OwnInstancePostUrl::among([SocialAccounts::mastodonOn('https://Mastodon.Social')], self::POST),
        );
    }

    public function testTheDefaultAccountsInstanceWins(): void
    {
        $default = SocialAccounts::mastodonOn('https://second.example');
        $default->becomeDefault();
        $accounts = [SocialAccounts::mastodonOn('https://first.example'), $default];

        self::assertStringStartsWith(
            'https://second.example/',
            (string) OwnInstancePostUrl::among($accounts, self::POST),
        );
    }

    public function testWithoutADefaultTheFirstAccountActs(): void
    {
        $accounts = [
            SocialAccounts::mastodonOn('https://first.example'),
            SocialAccounts::mastodonOn('https://second.example'),
        ];

        self::assertStringStartsWith(
            'https://first.example/',
            (string) OwnInstancePostUrl::among($accounts, self::POST),
        );
    }
}
