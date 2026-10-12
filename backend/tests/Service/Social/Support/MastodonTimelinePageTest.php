<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Support\MastodonTimelinePage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonTimelinePageTest extends TestCase
{
    private const string URI = 'https://mastodon.example/users/bob/statuses/109';
    private const string CONTENT = '<p>Hello <a href="https://example.com/story">example.com/story</a></p>';

    public function testAStatusBecomesAnEntryWithItsUriUrlAuthorAndUtcDate(): void
    {
        $page = MastodonTimelinePage::of([self::timelineStatus()]);
        $entry = $page->parsed->entries[0];

        self::assertSame(self::URI, $entry->guid);
        self::assertSame('https://mastodon.example/@bob/109', $entry->url);
        self::assertSame('Bob', $entry->author);
        self::assertSame('https://mastodon.example/@bob', $entry->authorUrl);
        self::assertSame(self::CONTENT, $entry->contentHtml);
        self::assertEquals(EntryTitle::of(null, self::CONTENT), $entry->headline);
        self::assertSame('2026-10-12 08:00:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame('109', $page->newestId);
    }

    public function testAContentWarningLeadsTheBody(): void
    {
        $status = self::timelineStatus(['spoiler_text' => 'Spoilers <3']);

        $entry = MastodonTimelinePage::of([$status])->parsed->entries[0];

        self::assertSame('<p><strong>CW: Spoilers &lt;3</strong></p>' . self::CONTENT, $entry->contentHtml);
    }

    public function testABoostShowsTheOriginalWithATrailingAttributionAtTheBoostTime(): void
    {
        $boost = self::timelineStatus([
            'id' => '200',
            'uri' => 'https://mastodon.example/users/carol/statuses/200/activity',
            'created_at' => '2026-10-12T09:30:00Z',
            'content' => '',
            'account' => ['acct' => 'carol', 'display_name' => 'Carol', 'url' => 'https://mastodon.example/@carol'],
            'reblog' => self::statusFields([]),
        ]);

        $page = MastodonTimelinePage::of([$boost]);
        $entry = $page->parsed->entries[0];

        self::assertSame(self::URI, $entry->guid);
        self::assertSame(
            self::CONTENT . '<p>Boosted by <a href="https://mastodon.example/@carol">Carol</a></p>',
            $entry->contentHtml,
        );
        self::assertEquals(EntryTitle::of(null, self::CONTENT), $entry->headline);
        self::assertSame('2026-10-12 09:30:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame('200', $page->newestId);
    }

    public function testImagesAndVideosBecomeMediaAndTheFirstImageLeads(): void
    {
        $entry = MastodonTimelinePage::of([self::timelineStatus(['media_attachments' => [
            [
                'type' => 'gifv',
                'url' => 'https://files.mastodon.example/b.mp4',
                'preview_url' => 'https://files.mastodon.example/b.jpg',
                'meta' => ['original' => ['width' => 640, 'height' => 360]],
            ],
            [
                'type' => 'image',
                'url' => 'https://files.mastodon.example/a.jpg',
                'meta' => ['original' => ['width' => 800, 'height' => 600]],
            ],
            ['type' => 'audio', 'url' => 'https://files.mastodon.example/c.mp3'],
        ]])])->parsed->entries[0];

        self::assertEquals(
            new DeclaredImageModel('https://files.mastodon.example/a.jpg', 800, 600),
            $entry->media->image,
        );
        self::assertEquals(
            [
                new ParsedMediumModel(
                    'https://files.mastodon.example/b.mp4',
                    VisualMediaKind::Video,
                    640,
                    360,
                    'https://files.mastodon.example/b.jpg',
                ),
                new ParsedMediumModel('https://files.mastodon.example/a.jpg', VisualMediaKind::Image, 800, 600),
            ],
            $entry->media->mediaBundle?->media,
        );
    }

    public function testAPreviewCardIsKeptAsTheLinksCard(): void
    {
        $page = MastodonTimelinePage::of([self::timelineStatus(['card' => [
            'url' => 'https://example.com/story',
            'title' => 'The story',
            'description' => 'What happened.',
            'image' => 'https://files.mastodon.example/card.jpg',
            'provider_name' => 'Example News',
        ]])]);

        self::assertEquals(
            new LinkCardContent(
                'https://example.com/story',
                'The story',
                'What happened.',
                'https://files.mastodon.example/card.jpg',
                'Example News',
            ),
            $page->knownShares->linkCardFor(self::URI),
        );
    }

    /** @param array<string, mixed> $card */
    #[DataProvider('unusableCards')]
    public function testACardLinkCardsCannotStoreFallsBackToThePageFetch(array $card): void
    {
        $page = MastodonTimelinePage::of([self::timelineStatus(['card' => $card])]);

        self::assertNull($page->knownShares->linkCardFor(self::URI));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableCards(): iterable
    {
        yield 'blank title' => [['url' => 'https://example.com/story', 'title' => ' ']];
        yield 'not a web page' => [['url' => 'ftp://example.com/story', 'title' => 'The story']];
        yield 'no url' => [['title' => 'The story']];
    }

    public function testTheNewestIdIsTheHighestIdWhateverTheOrder(): void
    {
        $page = MastodonTimelinePage::of([
            self::timelineStatus(['id' => '99', 'uri' => 'https://mastodon.example/users/bob/statuses/99']),
            self::timelineStatus(['id' => '1000', 'uri' => 'https://mastodon.example/users/bob/statuses/1000']),
            self::timelineStatus(['id' => '998', 'uri' => 'https://mastodon.example/users/bob/statuses/998']),
        ]);

        self::assertSame('1000', $page->newestId);
    }

    public function testAnEmptyPageNamesNoNewestId(): void
    {
        self::assertNull(MastodonTimelinePage::of([])->newestId);
    }

    /** @param array<string, mixed> $overrides */
    private static function timelineStatus(array $overrides = []): JsonNodeModel
    {
        return JsonNodeModel::of(self::statusFields($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function statusFields(array $overrides): array
    {
        return $overrides + [
            'id' => '109',
            'uri' => self::URI,
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T10:00:00.000+02:00',
            'content' => self::CONTENT,
            'spoiler_text' => '',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
            'media_attachments' => [],
            'reblog' => null,
            'card' => null,
        ];
    }
}
