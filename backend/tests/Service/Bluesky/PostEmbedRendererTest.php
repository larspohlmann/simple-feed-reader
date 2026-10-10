<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostEmbedRendererTest extends TestCase
{
    use ReadsFixtures;

    private const string MOTHER_JONES = 'https://bsky.app/profile/did:plc:qobvnkudcv3zlaklxxjduqoi/post/';
    private const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
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
    private const string FALLBACK = '<p><a href="https://bsky.app/profile/did:plc:a/post/b">'
        . 'View embedded content on Bluesky</a></p>';

    public function testALinkCardLinksTheArticleWithItsTitleDescriptionAndHost(): void
    {
        $embed = $this->rendered('external');

        self::assertSame(
            '<figure class="link-card"><a href="' . self::CARD . '"><img src="' . self::CARD_THUMB . '" alt="">'
                . '<strong>After ICE Shooting, Progressives Want New York&apos;s Police Commissioner to Step Down'
                . '</strong><span>28-year-old Oscar Belgal still has a bullet lodged in his body.</span>'
                . '<small>motherjones.com</small></a></figure>',
            $embed->html,
        );
        self::assertSame(self::CARD, $embed->linkCardUrl);
        self::assertSame(self::CARD_THUMB, $embed->leadImage?->url);
        self::assertSame([], $embed->media);
    }

    public function testAVideoPlaysThePlaylistUnderItsPosterAndLinksThePost(): void
    {
        $embed = $this->rendered('video');

        self::assertSame(
            '<figure class="post-video"><video controls preload="none" playsinline poster="' . self::VIDEO
                . 'thumbnail.jpg" src="' . self::VIDEO . 'playlist.m3u8"></video></figure>'
                . '<p><a href="' . self::MOTHER_JONES . '3mxhlfehxzi27">Watch on Bluesky</a></p>',
            $embed->html,
        );
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
        self::assertNull($embed->linkCardUrl);
    }

    public function testImagesShowFullSizeWithAltTextAndDimensions(): void
    {
        $embed = $this->rendered('images');

        self::assertSame(
            '<figure class="post-images"><img src="' . self::FULLSIZE . '" alt="An enormous pile of red apples and'
                . ' green apples—sweet, tart, delicious, and coming soon to an Apple Store near you! Keep your eye on'
                . ' this thread (and maybe put on a sturdy hat) to learn more about today&apos;s big drops."'
                . ' width="4000" height="3000"></figure>',
            $embed->html,
        );
        self::assertSame(
            [self::FULLSIZE, 4000, 3000],
            [$embed->leadImage?->url, $embed->leadImage?->width, $embed->leadImage?->height],
        );
        self::assertEquals([new ParsedMediumModel(self::FULLSIZE, VisualMediaKind::Image, 4000, 3000)], $embed->media);
    }

    public function testAQuoteShowsTheQuotedTextAndAuthorButNotTheQuotedPostsOwnEmbed(): void
    {
        $embed = $this->rendered('record');

        self::assertSame(
            '<figure class="quote-post"><blockquote><p>Big money has long dominated American politics, with wealthy'
                . ' donors, corporations, and special interests dumping tons of cash into presidential and'
                . ' congressional elections.</p><p>But this year, the quid pro quo of campaign money for preferential'
                . ' government treatment is more brazen than ever before.</p><footer><a href="' . self::MOTHER_JONES
                . '3mxhvfsp7n32t">Mother Jones (@motherjones.com)</a></footer></blockquote></figure>',
            $embed->html,
        );
        self::assertStringNotContainsString('stress-test', $embed->html);
        self::assertNull($embed->leadImage);
        self::assertNull($embed->linkCardUrl);
    }

    public function testAQuoteWithMediaRendersTheQuoteThenTheMedia(): void
    {
        $embed = $this->rendered('record-with-media');

        self::assertStringStartsWith('<figure class="quote-post"><blockquote><p>I spent months', $embed->html);
        self::assertStringContainsString(
            '<footer><a href="https://bsky.app/profile/did:plc:rhgbyqyye2vpydw7c75j4wnr/post/3mxh76vhjfk2k">'
                . 'Amy Littlefield (@amylittlefield.bsky.social)</a></footer></blockquote></figure>'
                . '<figure class="link-card"><a href="' . self::REDDIT_CARD . '">',
            $embed->html,
        );
        self::assertStringEndsWith(
            '<strong>From the IAmA community on Reddit</strong><span>Explore this post and more from the IAmA'
                . ' community</span><small>sh.reddit.com</small></a></figure>',
            $embed->html,
        );
        self::assertSame(self::REDDIT_CARD, $embed->linkCardUrl);
        self::assertSame(self::REDDIT_THUMB, $embed->leadImage?->url);
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
        yield 'a video on http' => [[
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'http://video.example/p.m3u8',
        ]];
        yield 'images on http only' => [[
            '$type' => 'app.bsky.embed.images#view',
            'images' => [['fullsize' => 'http://example.com/a.jpg', 'alt' => 'a']],
        ]];
        yield 'a link card to an http page' => [[
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'http://example.com/a', 'title' => 'A'],
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

    public function testHostileLinkCardTextIsEscapedAndAnHttpThumbnailDropped(): void
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

        self::assertSame(
            '<figure class="link-card"><a href="https://example.com/a?x=1&amp;y=2"><strong>&lt;script&gt;alert(1)'
                . '&lt;/script&gt;</strong><span>Fish &amp; &quot;chips&quot;</span><small>example.com</small></a>'
                . '</figure>',
            $rendered?->html,
        );
        self::assertNull($rendered->leadImage);
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

        self::assertSame(
            '<figure class="post-images"><img src="https://example.com/b.jpg" alt="&lt;b&gt;">'
                . '<img src="https://example.com/c.jpg" alt=""></figure>',
            $rendered?->html,
        );
        self::assertSame('https://example.com/b.jpg', $rendered->leadImage?->url);
        self::assertCount(2, $rendered->media);
    }

    public function testAVideoWithoutAThumbnailHasNoPosterAndNoLeadImage(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'https://video.example/p.m3u8',
        ]));

        self::assertStringStartsWith(
            '<figure class="post-video"><video controls preload="none" playsinline src="https://video.example/p.m3u8">',
            (string) $rendered?->html,
        );
        self::assertNull($rendered?->leadImage);
        self::assertNull($rendered?->media[0]->previewImageUrl);
    }

    public function testAQuotedAuthorsHostileNameAndTextAreEscaped(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'author' => ['handle' => 'evil.example', 'displayName' => '<img src=x onerror=alert(1)>'],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => "one <b>\ntwo"],
            ],
        ]));

        self::assertSame(
            '<figure class="quote-post"><blockquote><p>one &lt;b&gt;<br>two</p><footer>'
                . '<a href="https://bsky.app/profile/did:plc:c/post/d">&lt;img src=x onerror=alert(1)&gt;'
                . ' (@evil.example)</a></footer></blockquote></figure>',
            $rendered?->html,
        );
    }

    public function testAQuotedAuthorWithoutADisplayNameShowsTheHandle(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'author' => ['handle' => 'pantspants.bsky.social', 'displayName' => ' '],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => ''],
            ],
        ]));

        self::assertSame(
            '<figure class="quote-post"><blockquote><footer><a href="https://bsky.app/profile/did:plc:c/post/d">'
                . '@pantspants.bsky.social</a></footer></blockquote></figure>',
            $rendered?->html,
        );
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function sanitizedParts(): iterable
    {
        yield 'images' => ['images', [
            '<figure class="post-images"><img src="' . self::FULLSIZE . '" alt="An enormous pile',
            ' width="4000" height="3000" /></figure>',
        ]];
        yield 'video' => ['video', [
            '<figure class="post-video"><video controls preload="none" playsinline poster="' . self::VIDEO
                . 'thumbnail.jpg" src="' . self::VIDEO . 'playlist.m3u8"></video></figure>',
        ]];
        yield 'link card' => ['external', [
            '<figure class="link-card"><a href="' . self::CARD . '" rel="noopener noreferrer" target="_blank">'
                . '<img src="' . self::CARD_THUMB . '" alt /><strong>',
            '<small>motherjones.com</small></a></figure>',
        ]];
        yield 'quote' => ['record', [
            '<figure class="quote-post"><blockquote><p>Big money',
            '<footer><a href="' . self::MOTHER_JONES . '3mxhvfsp7n32t" rel="noopener noreferrer" target="_blank">'
                . 'Mother Jones (&#64;motherjones.com)</a></footer></blockquote></figure>',
        ]];
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

    private function rendered(string $fixture): RenderedEmbedModel
    {
        $answer = json_decode($this->fixture('Bluesky/' . $fixture . '.json'), true, flags: \JSON_THROW_ON_ERROR);
        $posts = JsonNodeModel::of($answer)->nodes('posts');
        self::assertCount(1, $posts);
        $rendered = $this->renderer()->render($posts[0]);
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
