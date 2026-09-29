<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Model;

use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use PHPUnit\Framework\TestCase;

final class MediaCandidateModelTest extends TestCase
{
    public function testFillsOnlyTheGapsFromTheLaterCandidate(): void
    {
        $declared = new MediaCandidateModel(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa',
            null,
            'Watch on YouTube',
        );
        $scanned = new MediaCandidateModel(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa',
            'https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg',
            'Scanned label',
            'The prose the player followed.',
            true,
        );

        $completed = $declared->completedBy($scanned);

        self::assertSame('https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $completed->posterUrl);
        self::assertSame('Watch on YouTube', $completed->label);
        self::assertSame('The prose the player followed.', $completed->precedingText);
        self::assertSame(MediaKind::Embed, $completed->kind);
        // Narration is a property of the media, not of the winning source, so a
        // later source that saw the tell flags the merged candidate.
        self::assertTrue($completed->narrated);
    }

    public function testKeepsEverythingItAlreadyHas(): void
    {
        $full = new MediaCandidateModel(
            MediaKind::Video,
            'https://x.test/a.mp4',
            'https://x.test/a.jpg',
            'A',
            'Prose A.',
        );
        $other = new MediaCandidateModel(
            MediaKind::Video,
            'https://x.test/a.mp4',
            'https://x.test/b.jpg',
            'B',
            'Prose B.',
        );

        $completed = $full->completedBy($other);

        self::assertSame('https://x.test/a.jpg', $completed->posterUrl);
        self::assertSame('A', $completed->label);
        self::assertSame('Prose A.', $completed->precedingText);
        // Neither side saw narration, so the merge stays un-narrated.
        self::assertFalse($completed->narrated);
    }

    public function testResolvePosterKeepsADiscoveredStillOverTheFallback(): void
    {
        $video = new MediaCandidateModel(
            MediaKind::Video,
            'https://x.test/a.mp4',
            'https://x.test/still.jpg',
            null,
            'P.',
        );

        $resolved = $video->resolvePoster('https://feed.test/fallback.jpg');

        self::assertSame($video, $resolved);
    }

    public function testResolvePosterRescuesAPosterlessVideoWithTheFallback(): void
    {
        $video = new MediaCandidateModel(MediaKind::Video, 'https://x.test/a.mp4', null, null, 'Prose.', true);

        $resolved = $video->resolvePoster('https://feed.test/fallback.jpg');

        self::assertNotNull($resolved);
        self::assertSame('https://feed.test/fallback.jpg', $resolved->posterUrl);
        self::assertSame('Prose.', $resolved->precedingText);
        self::assertTrue($resolved->narrated);
    }

    public function testResolvePosterDropsAVideoWhenNeitherStillNorFallbackExists(): void
    {
        $video = new MediaCandidateModel(MediaKind::Video, 'https://x.test/a.mp4', '', null);

        self::assertNull($video->resolvePoster(null));
    }

    public function testResolvePosterLeavesAudioUntouched(): void
    {
        $audio = new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3', null, null);

        self::assertSame($audio, $audio->resolvePoster('https://feed.test/fallback.jpg'));
    }

    public function testWithMimeTypeCarriesTheFeedDeclaredType(): void
    {
        $video = new MediaCandidateModel(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/p.jpg');

        self::assertSame('video/mp4', $video->withMimeType('video/mp4')->mimeType);
    }

    public function testWithMimeTypeIgnoresAMissingType(): void
    {
        $video = new MediaCandidateModel(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/p.jpg');

        self::assertSame($video, $video->withMimeType(null));
    }

    public function testResolvePosterKeepsTheMimeTypeWhenRescuing(): void
    {
        $video = (new MediaCandidateModel(MediaKind::Video, 'https://x.test/a.mp4'))->withMimeType('video/mp4');

        $resolved = $video->resolvePoster('https://feed.test/fallback.jpg');

        self::assertNotNull($resolved);
        self::assertSame('video/mp4', $resolved->mimeType);
    }

    public function testAtMovesOnlyTheUrl(): void
    {
        $declared = new MediaCandidateModel(MediaKind::Stream, 'https://a.test/x.m3u8', 'p.jpg', null, 'prose', true);

        $landed = $declared->at('https://cdn.test/master.m3u8');

        self::assertSame('https://cdn.test/master.m3u8', $landed->url);
        self::assertSame(MediaKind::Stream, $landed->kind);
        self::assertSame('p.jpg', $landed->posterUrl);
        self::assertSame('prose', $landed->precedingText);
        self::assertTrue($landed->narrated);
    }
}
