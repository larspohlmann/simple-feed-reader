<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\EntryMedium;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\MastodonQuotedStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonQuotedStatusTest extends TestCase
{
    public function testTheQuotedAuthorsStillAvatarIsPreferredAndAScriptAvatarDropped(): void
    {
        $still = self::quotedBy([
            'acct' => 'alice',
            'avatar' => 'https://mastodon.example/avatars/a.gif',
            'avatar_static' => 'https://mastodon.example/avatars/a.png',
        ]);
        $script = self::quotedBy(['acct' => 'alice', 'avatar' => 'javascript:alert(1)']);

        self::assertSame('https://mastodon.example/avatars/a.png', $still->authorAvatarUrl);
        self::assertNull($script->authorAvatarUrl);
    }

    public function testAnAcceptedQuoteIsTheQuotedStatusWithItsLineBreaksAndMedia(): void
    {
        $quote = self::quoted(MastodonQuotedStatus::ofQuote(
            JsonNodeModel::of(['state' => 'accepted', 'quoted_status' => self::statusFields()]),
        ));

        self::assertSame(
            [
                'https://mastodon.example/@alice/113',
                'Alice A.',
                'alice@mastodon.example',
                'https://mastodon.example/avatars/a.png',
                '2026-10-13 21:07:00',
                "First paragraph\nline two\nSecond paragraph",
            ],
            [
                $quote->url,
                $quote->authorName,
                $quote->authorHandle,
                $quote->authorAvatarUrl,
                $quote->postedAt?->format('Y-m-d H:i:s'),
                $quote->text,
            ],
        );
        self::assertEquals(
            [new EntryMedium(
                'https://files.mastodon.example/v.mp4',
                'video',
                1280,
                720,
                'https://files.mastodon.example/v.png',
            )],
            $quote->media,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function notAccepted(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'rejected' => ['rejected'];
        yield 'revoked' => ['revoked'];
        yield 'deleted' => ['deleted'];
    }

    #[DataProvider('notAccepted')]
    public function testAQuoteInAnyOtherStateIsNone(string $state): void
    {
        $quote = JsonNodeModel::of(['state' => $state, 'quoted_status' => self::statusFields()]);

        self::assertNull(MastodonQuotedStatus::ofQuote($quote));
    }

    public function testAShallowQuoteNamingOnlyAnIdIsNone(): void
    {
        $quote = JsonNodeModel::of(['state' => 'accepted', 'quoted_status_id' => '113']);

        self::assertNull(MastodonQuotedStatus::ofQuote($quote));
    }

    public function testARemoteAuthorKeepsTheirFullAcct(): void
    {
        $quote = self::quoted(MastodonQuotedStatus::of(JsonNodeModel::of(self::statusFields([
            'account' => ['acct' => 'bob@other.example', 'url' => 'https://other.example/@bob'],
        ]))));

        self::assertSame(['bob@other.example', 'bob@other.example'], [$quote->authorHandle, $quote->authorName]);
    }

    public function testAContentWarningLeadsTheText(): void
    {
        $quote = self::quoted(MastodonQuotedStatus::of(JsonNodeModel::of(self::statusFields([
            'spoiler_text' => 'spoilers',
        ]))));

        self::assertSame("CW: spoilers\nFirst paragraph\nline two\nSecond paragraph", $quote->text);
    }

    public function testTheQuotedStatusesOwnQuoteIsTheNestedLineAndLeavesItsText(): void
    {
        $quote = self::quoted(MastodonQuotedStatus::of(JsonNodeModel::of(self::statusFields([
            'content' => '<p>Look at this</p><p class="quote-inline">RE: '
                . '<a href="https://third.example/@carol/7">https://third.example/@carol/7</a></p>',
            'quote' => ['state' => 'accepted', 'quoted_status_id' => '7'],
        ]))));

        self::assertSame('Look at this', $quote->text);
        self::assertEquals(new QuotedPostReference('https://third.example/@carol/7', null), $quote->nestedQuote);
    }

    public function testAFullyAnsweredNestedQuoteNamesItsHandle(): void
    {
        $nested = self::statusFields([
            'url' => 'https://third.example/@carol/7',
            'account' => ['acct' => 'carol@third.example'],
        ]);

        $quote = self::quoted(MastodonQuotedStatus::of(JsonNodeModel::of(self::statusFields([
            'quote' => ['state' => 'accepted', 'quoted_status' => $nested],
        ]))));

        self::assertEquals(
            new QuotedPostReference('https://third.example/@carol/7', 'carol@third.example'),
            $quote->nestedQuote,
        );
    }

    /** @param array<string, string> $account */
    private static function quotedBy(array $account): QuotedPostContent
    {
        return self::quoted(MastodonQuotedStatus::ofQuote(JsonNodeModel::of([
            'state' => 'accepted',
            'quoted_status' => self::statusFields(['account' => $account]),
        ])));
    }

    private static function quoted(?QuotedPostContent $quote): QuotedPostContent
    {
        self::assertNotNull($quote);

        return $quote;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function statusFields(array $overrides = []): array
    {
        return [
            'uri' => 'https://mastodon.example/users/alice/statuses/113',
            'url' => 'https://mastodon.example/@alice/113',
            'created_at' => '2026-10-13T21:07:00.000Z',
            'content' => '<p>First paragraph<br>line two</p><p>Second paragraph</p>',
            'account' => [
                'acct' => 'alice',
                'display_name' => 'Alice A.',
                'url' => 'https://mastodon.example/@alice',
                'avatar' => 'https://mastodon.example/avatars/a.png',
            ],
            'media_attachments' => [[
                'type' => 'video',
                'url' => 'https://files.mastodon.example/v.mp4',
                'preview_url' => 'https://files.mastodon.example/v.png',
                'meta' => ['original' => ['width' => 1280, 'height' => 720]],
            ]],
            ...$overrides,
        ];
    }
}
