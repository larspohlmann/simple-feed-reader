<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Service\Bluesky\EntryEmbedWriter;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\Support\Bluesky;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class EntryEmbedWriterTest extends TestCase
{
    use ReadsFixtures;

    private const string CARD_THUMB = 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreie2nvxxbwowodsbtm3rksbshxxyrzr7jp6qkebllexxwjkmjs3a4y';
    private const string VIDEO = 'https://video.bsky.app/watch/did%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde/';
    private const string FULLSIZE = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:z72i7hdynmk6r22z27h6tvur/'
        . 'bafkreih3mb3cwnbc5kv5b2qyy24q6banms25i5ut3cbty4ej2x7vjvd6y4';

    public function testALinkCardReplacesTheTrailingUrlAndSetsSummaryAndImage(): void
    {
        $entry = self::entry('<p>Read this.<br />' . Bluesky::CARD . '</p>');

        self::assertTrue($this->writer()->fill($entry, $this->post('external')));

        self::assertSame(
            '<p>Read this.</p><figure class="link-card"><a href="' . Bluesky::CARD . '" rel="noopener noreferrer"'
                . ' target="_blank"><img src="' . self::CARD_THUMB . '" alt /><strong>After ICE Shooting, Progressives'
                . ' Want New York&#039;s Police Commissioner to Step Down</strong><span>28-year-old Oscar Belgal still'
                . ' has a bullet lodged in his body.</span><small>motherjones.com</small></a></figure>',
            $entry->getContentHtml(),
        );
        self::assertSame('Read this.', $entry->getSummary());
        self::assertSame(self::CARD_THUMB, $entry->getImageUrl());
        self::assertEquals([new EntryMedium(self::CARD_THUMB, 'image')], $entry->getMedia());
        self::assertSame('Post', $entry->getTitle());
    }

    public function testAnotherUrlAtTheEndStaysInTheText(): void
    {
        $entry = self::entry('<p>See https://example.com/other</p>');

        $this->writer()->fill($entry, $this->post('external'));

        self::assertStringStartsWith(
            '<p>See https://example.com/other</p><figure class="link-card">',
            (string) $entry->getContentHtml(),
        );
        self::assertSame('See https://example.com/other', $entry->getSummary());
    }

    public function testAnHttpLinkCardReplacesTheTrailingUrlToo(): void
    {
        $entry = self::entry('<p>Scores tonight.<br />http://spr.ly/6018AbCdE</p>');

        $this->writer()->fill($entry, JsonNodeModel::of([
            'uri' => 'at://did:plc:a/app.bsky.feed.post/b',
            'embed' => [
                '$type' => 'app.bsky.embed.external#view',
                'external' => ['uri' => 'http://spr.ly/6018AbCdE', 'title' => 'Scores'],
            ],
        ]));

        self::assertSame(
            '<p>Scores tonight.</p><figure class="link-card"><a href="http://spr.ly/6018AbCdE"'
                . ' rel="noopener noreferrer" target="_blank"><strong>Scores</strong><small>spr.ly</small></a>'
                . '</figure>',
            $entry->getContentHtml(),
        );
        self::assertSame('Scores tonight.', $entry->getSummary());
    }

    public function testAVideoAddsThePosterAsImageAndThePlaylistAsMedia(): void
    {
        $entry = self::entry('<p>Watch.</p>');

        $this->writer()->fill($entry, $this->post('video'));

        self::assertStringStartsWith(
            '<p>Watch.</p><figure class="post-video"><video controls',
            (string) $entry->getContentHtml(),
        );
        self::assertSame(self::VIDEO . 'thumbnail.jpg', $entry->getImageUrl());
        self::assertSame([1080, 1920], [$entry->getImageWidth(), $entry->getImageHeight()]);
        self::assertEquals(
            [
                new EntryMedium(self::VIDEO . 'thumbnail.jpg', 'image', 1080, 1920),
                new EntryMedium(self::VIDEO . 'playlist.m3u8', 'video', 1080, 1920, self::VIDEO . 'thumbnail.jpg'),
            ],
            $entry->getMedia(),
        );
    }

    public function testAnImageTheEntryAlreadyHasIsKeptAndLeadsTheMedia(): void
    {
        $entry = self::entry('<p>Apples.</p>');
        $entry->getImage()->storePending('https://example.com/own.jpg', 800, 600);

        $this->writer()->fill($entry, $this->post('images'));

        self::assertSame('https://example.com/own.jpg', $entry->getImageUrl());
        self::assertEquals(
            [
                new EntryMedium('https://example.com/own.jpg', 'image', 800, 600),
                new EntryMedium(self::FULLSIZE, 'image', 4000, 3000),
            ],
            $entry->getMedia(),
        );
    }

    public function testAnEmptyBodyGetsTheEmbedAndNoSummary(): void
    {
        $entry = self::entry(null);

        $this->writer()->fill($entry, $this->post('images'));

        self::assertStringStartsWith(
            '<figure class="post-images"><img src="' . self::FULLSIZE . '"',
            (string) $entry->getContentHtml(),
        );
        self::assertNull($entry->getSummary());
        self::assertSame(self::FULLSIZE, $entry->getImageUrl());
    }

    public function testTheCombinedHtmlIsSanitizedBeforeItIsStored(): void
    {
        $entry = self::entry('<p onclick="steal()">Hi.</p><script>alert(1)</script>');

        $this->writer()->fill($entry, $this->post('images'));

        $stored = (string) $entry->getContentHtml();
        self::assertStringStartsWith('<p>Hi.</p><figure class="post-images">', $stored);
        self::assertStringNotContainsString('script', $stored);
        self::assertStringNotContainsString('onclick', $stored);
    }

    public function testAPostWithoutAnEmbedLeavesTheEntryAlone(): void
    {
        $entry = self::entry('<p>Plain.</p>');
        $entry->setSummary('Plain.');

        self::assertFalse(
            $this->writer()->fill($entry, JsonNodeModel::of(['uri' => 'at://did:plc:a/app.bsky.feed.post/b'])),
        );

        self::assertSame('<p>Plain.</p>', $entry->getContentHtml());
        self::assertSame('Plain.', $entry->getSummary());
        self::assertNull($entry->getImageUrl());
        self::assertSame([], $entry->getMedia());
    }

    private static function entry(?string $contentHtml): Entry
    {
        $entry = new Entry(
            new Feed('https://bsky.app/profile/motherjones.com/rss'),
            'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t',
            null,
            'Post',
            new \DateTimeImmutable('2026-10-10 12:00:00'),
            new \DateTimeImmutable('2026-10-10 12:00:00'),
        );
        $entry->setContentHtml($contentHtml);
        $entry->getImage()->storePending(null, null, null);

        return $entry;
    }

    private function post(string $fixture): JsonNodeModel
    {
        $answer = json_decode($this->fixture('Bluesky/' . $fixture . '.json'), true, flags: \JSON_THROW_ON_ERROR);
        $posts = JsonNodeModel::of($answer)->nodes('posts');
        self::assertCount(1, $posts);

        return $posts[0];
    }

    private function writer(): EntryEmbedWriter
    {
        return new EntryEmbedWriter(
            new PostEmbedRenderer(),
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
        );
    }
}
