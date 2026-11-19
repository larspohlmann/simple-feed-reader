<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Enum\SocialNetwork;
use App\Service\Social\Mastodon\MastodonPostAddressRule;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonPostAddressRuleTest extends TestCase
{
    #[DataProvider('mastodonStatuses')]
    public function testAMastodonStatusIsDeletedByItsIdOnTheAccountsServer(string $address): void
    {
        $post = new SocialPostAddressModel(SocialNetwork::Mastodon, $address);
        $rule = new MastodonPostAddressRule();

        self::assertSame('120', $rule->requireOwnPostId($post, SocialAccounts::mastodon()));
        self::assertSame('120', $rule->requireOwnReplyId($address, SocialAccounts::mastodon()));
    }

    /** @return iterable<string, array{string}> */
    public static function mastodonStatuses(): iterable
    {
        yield 'web address' => ['https://mastodon.example/@alice/120'];
        yield 'activity address' => ['https://Mastodon.Example/users/alice/statuses/120'];
    }

    #[DataProvider('unaddressable')]
    public function testAPostThatCannotBeNamedOnTheAccountsServerIsRefused(string $url): void
    {
        $this->expectException(SocialPostRefusedException::class);

        new MastodonPostAddressRule()->requireOwnReplyId($url, SocialAccounts::mastodon());
    }

    /** @return iterable<string, array{string}> */
    public static function unaddressable(): iterable
    {
        yield 'a Mastodon status on another server' => ['https://elsewhere.example/@alice/120'];
        yield 'no Mastodon status' => ['https://mastodon.example/@alice'];
    }

    public function testAMastodonReplyComesBackUnderItsStatusUriOrItsWebAddress(): void
    {
        self::assertSame(
            ['https://mastodon.example/users/alice/statuses/121', 'https://mastodon.example/@alice/121'],
            new MastodonPostAddressRule()->requireOwnReplyGuids(
                'https://mastodon.example/@alice/121',
                SocialAccounts::mastodon(),
            ),
        );
    }

    public function testAnAddressThatNamesNoMastodonStatusIsRefused(): void
    {
        $this->expectException(SocialPostRefusedException::class);

        new MastodonPostAddressRule()->requireOwnReplyGuids(
            'https://mastodon.example/@alice',
            SocialAccounts::mastodon(),
        );
    }
}
