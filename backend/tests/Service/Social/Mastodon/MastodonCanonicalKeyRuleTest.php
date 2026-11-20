<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Mastodon\MastodonCanonicalKeyRule;
use App\Service\Social\Mastodon\MastodonPostAddressRule;
use App\Tests\Support\CanonicalKeyRuleEntries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonCanonicalKeyRuleTest extends TestCase
{
    private const string KEY = 'https://mastodon.social/users/alice/statuses/123';

    public function testTimelineAndRssFormsShareOneKey(): void
    {
        $rule = new MastodonCanonicalKeyRule();

        $timeline = $rule->findKey(CanonicalKeyRuleEntries::buildEntry(self::KEY, null, SocialNetwork::Mastodon));
        $rssByGuid = $rule->findKey(
            CanonicalKeyRuleEntries::buildEntry('https://mastodon.social/@alice/123', null, SocialNetwork::Mastodon),
        );
        $rssByUrl = $rule->findKey(
            CanonicalKeyRuleEntries::buildEntry(
                'tag:mastodon.social,2026:123',
                'https://mastodon.social/@alice/123',
                SocialNetwork::Mastodon,
            ),
        );

        self::assertSame(CanonicalItemKind::Mastodon, $timeline?->kind);
        self::assertSame(self::KEY, $timeline->key);
        self::assertSame(self::KEY, $rssByGuid?->key);
        self::assertSame(self::KEY, $rssByUrl?->key);
    }

    public function testTheGuidWinsOverTheUrl(): void
    {
        $key = new MastodonCanonicalKeyRule()->findKey(
            CanonicalKeyRuleEntries::buildEntry(self::KEY, 'https://mastodon.social/@bob/999', SocialNetwork::Mastodon),
        );

        self::assertSame(self::KEY, $key?->key);
    }

    public function testAnAbsoluteGuidWinsOverAnAbsoluteUrlWhenNeitherIsAStatus(): void
    {
        $key = new MastodonCanonicalKeyRule()->findKey(
            CanonicalKeyRuleEntries::buildEntry(
                'https://example.org/plain/1',
                'https://example.org/plain/2',
                SocialNetwork::Mastodon,
            ),
        );

        self::assertSame('https://example.org/plain/1', $key?->key);
    }

    public function testSavedCopyGuidKeysAsItsOriginal(): void
    {
        $key = new MastodonCanonicalKeyRule()->findKey(
            CanonicalKeyRuleEntries::buildEntry(self::KEY . '#42', null, SocialNetwork::Mastodon),
        );

        self::assertSame(self::KEY, $key?->key);
    }

    #[DataProvider('otherFediverseAddresses')]
    public function testAStatusOfAnotherFediverseServerKeysAsItsAddress(string $address): void
    {
        $key = new MastodonCanonicalKeyRule()->findKey(
            CanonicalKeyRuleEntries::buildEntry($address, null, SocialNetwork::Mastodon),
        );

        self::assertSame($address, $key?->key);
    }

    /** @return iterable<string, array{string}> */
    public static function otherFediverseAddresses(): iterable
    {
        yield 'GoToSocial' => ['https://gts.example/users/bob/statuses/01HXYZ'];
        yield 'Misskey' => ['https://misskey.example/notes/9abc'];
    }

    public function testRuleAnswersExactlyWhenThePostAddressRuleFindsAPost(): void
    {
        $fixtures = [
            CanonicalKeyRuleEntries::buildEntry(self::KEY, null, SocialNetwork::Mastodon),
            CanonicalKeyRuleEntries::buildEntry('https://mastodon.social/@alice/123', null, SocialNetwork::Mastodon),
            CanonicalKeyRuleEntries::buildEntry(
                'tag:x,2026:1',
                'https://mastodon.social/@alice/123',
                SocialNetwork::Mastodon,
            ),
            CanonicalKeyRuleEntries::buildEntry('https://misskey.example/notes/9abc', null, SocialNetwork::Mastodon),
            CanonicalKeyRuleEntries::buildEntry('tag:x,2026:1', null, SocialNetwork::Mastodon),
            CanonicalKeyRuleEntries::buildEntry('urn:uuid:1', 'not a url', SocialNetwork::Mastodon),
        ];

        foreach ($fixtures as $entry) {
            self::assertSame(
                null !== new MastodonPostAddressRule()->findPost($entry),
                null !== new MastodonCanonicalKeyRule()->findKey($entry),
            );
        }
    }

    public function testEntryOfAnotherNetworkHasNoKey(): void
    {
        self::assertNull(
            new MastodonCanonicalKeyRule()->findKey(
                CanonicalKeyRuleEntries::buildEntry(self::KEY, null, SocialNetwork::Lemmy),
            ),
        );
    }

    public function testArticleFeedHasNoKey(): void
    {
        self::assertNull(
            new MastodonCanonicalKeyRule()->findKey(CanonicalKeyRuleEntries::buildEntry(self::KEY, null, null)),
        );
    }
}
