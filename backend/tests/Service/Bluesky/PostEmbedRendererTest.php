<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\Support\Bluesky;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostEmbedRendererTest extends TestCase
{
    private const string THUMBNAILS = 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/';
    private const string CARD_THUMB = self::THUMBNAILS
        . 'bafkreie2nvxxbwowodsbtm3rksbshxxyrzr7jp6qkebllexxwjkmjs3a4y';
    private const string VIDEO = 'https://video.bsky.app/watch/did%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde/';
    private const string FULLSIZE = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:z72i7hdynmk6r22z27h6tvur/'
        . 'bafkreih3mb3cwnbc5kv5b2qyy24q6banms25i5ut3cbty4ej2x7vjvd6y4';
    private const string REDDIT_CARD = 'https://sh.reddit.com/r/IAmA/comments/1x1n1i1/'
        . 'after_the_fall_of_roe_v_wade_the_antiabortion/';
    private const string REDDIT_THUMB = self::THUMBNAILS
        . 'bafkreihbcj6mbqkkoffjnzm4qlwje6vezlsydmbv4hljgllcqrkjs2kg7q';
    private const string KLIPY_GIF = 'https://static.klipy.com/ii/d6b0ce929193df3c242ac34b5654d2ce/35/22/'
        . 'scTbQIaa.gif?hh=280&ww=498&mp4=08W9PvUtuFHM97&webm=QTjWv8sXYpc9WKG9eRu';
    private const string FALLBACK = '<p><a href="https://bsky.app/profile/did:plc:a/post/b">'
        . 'View embedded content on Bluesky</a></p>';

    public function testALinkCardIsAValueWithTitleDescriptionThumbnailAndHostAndNoHtml(): void
    {
        $embed = $this->rendered('external');

        self::assertSame('', $embed->html);
        self::assertEquals([new LinkCardContent(
            Bluesky::CARD,
            'After ICE Shooting, Progressives Want New York\'s Police Commissioner to Step Down',
            '28-year-old Oscar Belgal still has a bullet lodged in his body.',
            self::CARD_THUMB,
            'motherjones.com',
        )], $embed->linkCards);
        self::assertSame(self::CARD_THUMB, $embed->leadImage?->url);
        self::assertSame([], $embed->media);
    }

    public function testAVideoKeepsItsPlaylistAndPosterInMediaAndLeavesTheBodyEmpty(): void
    {
        $embed = $this->rendered('video');

        self::assertSame('', $embed->html);
        self::assertSame(
            [self::VIDEO . 'thumbnail.jpg', 1080, 1920],
            [$embed->leadImage?->url, $embed->leadImage?->width, $embed->leadImage?->height],
        );
        self::assertEquals(
            [new ParsedMediumModel(
                self::VIDEO . 'playlist.m3u8',
                VisualMediaKind::Video,
                1080,
                1920,
                self::VIDEO . 'thumbnail.jpg',
            )],
            $embed->media,
        );
        self::assertSame([], $embed->linkCards);
    }

    public function testImagesLeaveTheBodyEmptyAndKeepTheirFullSizeInMedia(): void
    {
        $embed = $this->rendered('images');

        self::assertSame('', $embed->html);
        self::assertSame(
            [self::FULLSIZE, 4000, 3000],
            [$embed->leadImage?->url, $embed->leadImage?->width, $embed->leadImage?->height],
        );
        self::assertEquals([new ParsedMediumModel(
            self::FULLSIZE,
            VisualMediaKind::Image,
            4000,
            3000,
            alt: 'An enormous pile of red apples and green apples—sweet, tart, delicious, and coming soon to an Apple'
                . ' Store near you! Keep your eye on this thread (and maybe put on a sturdy hat) to learn more about'
                . ' today\'s big drops.',
        )], $embed->media);
    }

    public function testAQuoteIsAQuotedPostWithNoHtmlNoCardAndNoLeadImage(): void
    {
        $embed = $this->rendered('record');

        self::assertSame('', $embed->html);
        self::assertSame([], $embed->linkCards);
        self::assertNull($embed->leadImage);
        self::assertNotNull($embed->quote);
    }

    public function testAQuoteWithMediaGivesTheQuoteAndOnlyThePostsOwnCard(): void
    {
        $embed = $this->rendered('record-with-media');

        self::assertSame('amylittlefield.bsky.social', $embed->quote?->authorHandle);
        self::assertSame(
            [self::REDDIT_CARD],
            array_map(static fn (LinkCardContent $card): string => $card->url, $embed->linkCards),
        );
        self::assertSame(self::REDDIT_THUMB, $embed->leadImage?->url);
    }

    public function testTheQuoteOfAVideoNoLongerBecomesALinkCard(): void
    {
        $embed = $this->rendered('quote-video');

        self::assertSame([], $embed->linkCards);
        self::assertSame('video', $embed->quote?->media[0]->kind);
    }

    public function testAStarterPackLinksThePostOnBluesky(): void
    {
        self::assertSame(
            '<p><a href="https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mwolmfws5k2r">'
                . 'View embedded content on Bluesky</a></p>',
            $this->rendered('starter-pack')->html,
        );
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function unrenderableEmbeds(): iterable
    {
        yield 'an unknown type' => [['$type' => 'app.bsky.embed.somethingNew#view']];
        yield 'a quoted post that is gone' => [[
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewNotFound',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'notFound' => true,
            ],
        ]];
        yield 'a quoted record that is no post' => [[
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'value' => ['$type' => 'app.bsky.graph.starterpack', 'text' => 'Pack'],
            ],
        ]];
        yield 'a quoted post whose uri is no post uri' => [[
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'https://example.com/d',
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'Text'],
            ],
        ]];
        yield 'a video on http' => [[
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'http://video.example/p.m3u8',
        ]];
        yield 'images on http only' => [[
            '$type' => 'app.bsky.embed.images#view',
            'images' => [['fullsize' => 'http://example.com/a.jpg', 'alt' => 'a']],
        ]];
        yield 'a link card to a javascript: uri' => [[
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'javascript:alert(1)//https://example.com/', 'title' => 'A'],
        ]];
        yield 'a link card to a data: uri' => [[
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'data:text/html,<script>alert(1)</script>', 'title' => 'A'],
        ]];
        yield 'a link card to a protocol-relative uri' => [[
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => '//example.com/a', 'title' => 'A'],
        ]];
    }

    /** @param array<mixed> $embed */
    #[DataProvider('unrenderableEmbeds')]
    public function testAnEmbedItCannotRenderLinksThePost(array $embed): void
    {
        $rendered = $this->renderer()->render(self::post($embed));

        self::assertSame(self::FALLBACK, $rendered?->html);
        self::assertNull($rendered->leadImage);
    }

    public function testAPostWithoutAnEmbedRendersNothing(): void
    {
        $post = JsonNodeModel::of(['uri' => 'at://did:plc:a/app.bsky.feed.post/b']);

        self::assertNull($this->renderer()->render($post));
    }

    public function testAPostWhoseUriIsNoPostsRendersNothing(): void
    {
        self::assertNull($this->renderer()->render(JsonNodeModel::of([
            'uri' => 'at://did:plc:a/app.bsky.feed.repost/b',
            'embed' => ['$type' => 'app.bsky.embed.somethingNew#view'],
        ])));
    }

    public function testHostileLinkCardTextStaysPlainTextAndAnHttpThumbnailIsDropped(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://example.com/a?x=1&y=2',
                'title' => '<script>alert(1)</script>',
                'description' => 'Fish & "chips"',
                'thumb' => 'http://example.com/t.jpg',
            ],
        ]));

        self::assertEquals([new LinkCardContent(
            'https://example.com/a?x=1&y=2',
            '<script>alert(1)</script>',
            'Fish & "chips"',
            null,
            'example.com',
        )], $rendered?->linkCards);
        self::assertNull($rendered?->leadImage);
    }

    public function testALinkCardMayLinkAnHttpPageButItsThumbnailStaysHttpsOnly(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'http://spr.ly/6018AbCdE',
                'title' => 'Scores',
                'thumb' => 'http://example.com/t.jpg',
            ],
        ]));

        self::assertEquals(
            [new LinkCardContent('http://spr.ly/6018AbCdE', 'Scores', null, null, 'spr.ly')],
            $rendered?->linkCards,
        );
        self::assertNull($rendered?->leadImage);
    }

    public function testALinkCardShowsItsHostLowerCasedWithoutWww(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://WWW.Example.COM/Story', 'title' => 'Story'],
        ]));

        self::assertSame('example.com', self::onlyCard($rendered)->siteName);
    }

    public function testALinkWithoutATitleIsLeftUncardedWithNoHtml(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://example.com/story', 'title' => ' ', 'description' => 'No headline'],
        ]));

        self::assertSame('', $rendered?->html);
        self::assertSame([], $rendered->linkCards);
        self::assertSame('https://example.com/story', $rendered->uncardedLink);
    }

    public function testAQuoteWithAnUntitledLinkGivesTheQuotedPostAndLeavesTheLinkUncarded(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'record' => ['record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:q/app.bsky.feed.post/r',
                'author' => ['handle' => 'quoted.test'],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'Quoted.'],
            ]],
            'media' => ['$type' => 'app.bsky.embed.external#view', 'external' => ['uri' => 'https://example.com/a']],
        ]));

        self::assertSame('', $rendered?->html);
        self::assertSame('quoted.test', $rendered->quote?->authorHandle);
        self::assertSame([], $rendered->linkCards);
        self::assertSame('https://example.com/a', $rendered->uncardedLink);
    }

    public function testALinkCardTooLongToStoreIsNoCardAndLinksThePost(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://example.com/' . str_repeat('a', 2080), 'title' => 'Story'],
        ]));

        self::assertSame(self::FALLBACK, $rendered?->html);
        self::assertSame([], $rendered->linkCards);
    }

    public function testALinkCardsLongTitleAndDescriptionAreCutToFit(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://example.com/story',
                'title' => str_repeat('t', 600),
                'description' => str_repeat('word ', 100),
            ],
        ]));

        $card = self::onlyCard($rendered);
        self::assertSame(str_repeat('t', 500), $card->title);
        self::assertSame(str_repeat('word ', 58) . 'word…', $card->description);
    }

    public function testAnHttpImageIsDroppedAndAProtocolRelativeOneUpgraded(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.images#view',
            'images' => [
                ['fullsize' => 'http://example.com/a.jpg', 'alt' => 'a'],
                ['fullsize' => 'https://example.com/b.jpg', 'alt' => '<b>'],
                ['fullsize' => '//example.com/c.jpg'],
            ],
        ]));

        self::assertSame('', $rendered?->html);
        self::assertSame('https://example.com/b.jpg', $rendered->leadImage?->url);
        self::assertSame(
            ['https://example.com/b.jpg', 'https://example.com/c.jpg'],
            array_map(static fn (ParsedMediumModel $medium): string => $medium->url, $rendered->media),
        );
        self::assertSame(['<b>', null], array_map(
            static fn (ParsedMediumModel $medium): ?string => $medium->alt,
            $rendered->media,
        ));
    }

    public function testAnImageWithHalfAnAspectRatioKeepsOnlyTheDimensionItHas(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.images#view',
            'images' => [['fullsize' => 'https://example.com/a.jpg', 'aspectRatio' => ['width' => 800]]],
        ]));

        self::assertSame('', $rendered?->html);
        self::assertSame([800, null], [$rendered->media[0]->width, $rendered->media[0]->height]);
    }

    public function testAGifPickerEmbedIsAnAnimatedImage(): void
    {
        $rendered = $this->renderedPost(self::gifPicker(self::KLIPY_GIF));

        self::assertEquals(
            [new ParsedMediumModel(
                self::KLIPY_GIF,
                VisualMediaKind::Image,
                498,
                280,
                self::CARD_THUMB,
                'Audience Clapping and Applauding',
                isAnimated: true,
            )],
            $rendered->media,
        );
        self::assertSame(
            [self::CARD_THUMB, 498, 280],
            [$rendered->leadImage?->url, $rendered->leadImage?->width, $rendered->leadImage?->height],
        );
        self::assertSame('', $rendered->html);
        self::assertSame([], $rendered->linkCards);
        self::assertNull($rendered->uncardedLink);
    }

    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function gifAltTexts(): iterable
    {
        yield 'no description' => [['title' => 'Clapping'], 'Clapping'];
        yield 'a bare ALT: prefix' => [['title' => 'Clapping', 'description' => 'ALT: '], 'Clapping'];
        yield 'ALT: without a space' => [['title' => 'Clapping', 'description' => 'ALT:Applause'], 'Applause'];
        yield 'a description without the prefix' => [['title' => 'Clapping', 'description' => 'Applause'], 'Applause'];
        yield 'neither' => [[], null];
        yield 'a blank title' => [['title' => '  '], null];
    }

    /** @param array<string, string> $text */
    #[DataProvider('gifAltTexts')]
    public function testAGifsAltTextIsItsDescriptionWithoutThePickersPrefixElseItsTitle(array $text, ?string $alt): void
    {
        $rendered = $this->renderedPost([
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://media.tenor.com/abc/Clap.GIF?hh=10&ww=20', ...$text],
        ]);

        self::assertCount(1, $rendered->media);
        self::assertSame($alt, $rendered->media[0]->alt);
        self::assertSame('', $rendered->html);
    }

    public function testAGifWithoutAThumbHasNoStillAndNoLeadImage(): void
    {
        $rendered = $this->renderedPost([
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://media.tenor.com/abc/clap.gif?hh=10&ww=20', 'title' => 'Clapping'],
        ]);

        self::assertCount(1, $rendered->media);
        self::assertNull($rendered->media[0]->previewImageUrl);
        self::assertNull($rendered->leadImage);
    }

    public function testAGifPickerEmbedInsideAQuoteIsAnAnimatedImageToo(): void
    {
        $rendered = $this->renderedPost([
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'record' => ['record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:q/app.bsky.feed.post/r',
                'author' => ['handle' => 'quoted.test'],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'Quoted.'],
            ]],
            'media' => self::gifPicker(self::KLIPY_GIF),
        ]);

        self::assertSame('quoted.test', $rendered->quote?->authorHandle);
        self::assertSame([self::KLIPY_GIF], array_map(
            static fn (ParsedMediumModel $medium): string => $medium->url,
            $rendered->media,
        ));
        self::assertSame([], $rendered->linkCards);
    }

    public function testAOnePixelGifIsStillAGif(): void
    {
        $rendered = $this->renderedPost(self::gifPicker('https://media.tenor.com/a/dot.gif?hh=1&ww=1'));

        self::assertCount(1, $rendered->media);
        self::assertSame([1, 1], [$rendered->media[0]->width, $rendered->media[0]->height]);
    }

    /** @return iterable<string, array{string}> */
    public static function gifsThatStayLinkCards(): iterable
    {
        yield 'no size' => ['https://static.klipy.com/ii/a/b.gif'];
        yield 'only a width' => ['https://static.klipy.com/ii/a/b.gif?ww=498'];
        yield 'a zero height' => ['https://static.klipy.com/ii/a/b.gif?hh=0&ww=498'];
        yield 'a size that is no integer' => ['https://static.klipy.com/ii/a/b.gif?hh=2.5&ww=498'];
        yield 'a size given as a list' => ['https://static.klipy.com/ii/a/b.gif?hh[]=2&ww=498'];
        yield 'on http' => ['http://static.klipy.com/ii/a/b.gif?hh=280&ww=498'];
        yield 'a path that only contains .gif' => ['https://example.com/b.gif/page?hh=280&ww=498'];
    }

    #[DataProvider('gifsThatStayLinkCards')]
    public function testAGifWithoutItsSizeStaysALinkCard(string $url): void
    {
        $rendered = $this->renderedPost(self::gifPicker($url));

        self::assertSame([$url], array_map(
            static fn (LinkCardContent $card): string => $card->url,
            $rendered->linkCards,
        ));
        self::assertSame([], $rendered->media);
    }

    public function testAnOrdinaryExternalEmbedIsStillALinkCard(): void
    {
        $rendered = $this->renderedPost([
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://example.com/story?hh=280&ww=498',
                'title' => 'Story',
                'description' => 'ALT: not a gif',
                'thumb' => 'https://example.com/t.jpg',
            ],
        ]);

        self::assertEquals([new LinkCardContent(
            'https://example.com/story?hh=280&ww=498',
            'Story',
            'ALT: not a gif',
            'https://example.com/t.jpg',
            'example.com',
        )], $rendered->linkCards);
        self::assertSame([], $rendered->media);
        self::assertSame('', $rendered->html);
    }

    public function testAVideoWithoutAThumbnailHasNoPreviewAndNoLeadImage(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'https://video.example/p.m3u8',
        ]));

        self::assertSame('', $rendered?->html);
        self::assertNull($rendered->leadImage);
        self::assertNull($rendered->media[0]->previewImageUrl);
    }

    public function testAVideoKeepsItsAltText(): void
    {
        $rendered = $this->renderedPost([
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'https://video.example/p.m3u8',
            'alt' => 'A donkey trots past',
        ]);

        self::assertSame('A donkey trots past', $rendered->media[0]->alt);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function sanitizedParts(): iterable
    {
        yield 'fallback' => ['starter-pack', [
            '<p><a href="https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mwolmfws5k2r"'
                . ' rel="noopener noreferrer" target="_blank">View embedded content on Bluesky</a></p>',
        ]];
    }

    /** @param list<string> $parts */
    #[DataProvider('sanitizedParts')]
    public function testTheRenderedEmbedSurvivesTheSanitizer(string $fixture, array $parts): void
    {
        $sanitizer = new EntrySanitizer(new TrailingBlankRemover());
        $sanitized = (string) $sanitizer->sanitize($this->rendered($fixture)->html);

        foreach ($parts as $part) {
            self::assertStringContainsString($part, $sanitized);
        }
    }

    private static function onlyCard(?RenderedEmbedModel $rendered): LinkCardContent
    {
        self::assertNotNull($rendered);
        self::assertCount(1, $rendered->linkCards);

        return $rendered->linkCards[0];
    }

    private function rendered(string $fixture): RenderedEmbedModel
    {
        $rendered = $this->renderer()->render(Bluesky::post($fixture));
        self::assertNotNull($rendered);

        return $rendered;
    }

    /** @return array<string, mixed> */
    private static function gifPicker(string $url): array
    {
        return [
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => $url,
                'title' => 'Audience Clapping and Applauding',
                'description' => 'ALT: Audience Clapping and Applauding',
                'thumb' => self::CARD_THUMB,
            ],
        ];
    }

    /** @param array<mixed> $embed */
    private function renderedPost(array $embed): RenderedEmbedModel
    {
        $rendered = $this->renderer()->render(self::post($embed));
        self::assertNotNull($rendered);

        return $rendered;
    }

    /** @param array<mixed> $embed */
    private static function post(array $embed): JsonNodeModel
    {
        return JsonNodeModel::of(['uri' => 'at://did:plc:a/app.bsky.feed.post/b', 'embed' => $embed]);
    }

    private function renderer(): PostEmbedRenderer
    {
        return new PostEmbedRenderer();
    }
}
