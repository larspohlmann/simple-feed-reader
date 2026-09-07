<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use PHPUnit\Framework\TestCase;

final class MediaCandidateTest extends TestCase
{
    public function testFillsOnlyTheGapsFromTheLaterCandidate(): void
    {
        $declared = new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa',
            null,
            'Watch on YouTube',
        );
        $scanned = new MediaCandidate(
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
        $full = new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/a.jpg', 'A', 'Prose A.');
        $other = new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/b.jpg', 'B', 'Prose B.');

        $completed = $full->completedBy($other);

        self::assertSame('https://x.test/a.jpg', $completed->posterUrl);
        self::assertSame('A', $completed->label);
        self::assertSame('Prose A.', $completed->precedingText);
        // Neither side saw narration, so the merge stays un-narrated.
        self::assertFalse($completed->narrated);
    }

    public function testAtMovesOnlyTheUrl(): void
    {
        $declared = new MediaCandidate(MediaKind::Stream, 'https://a.test/x.m3u8', 'p.jpg', null, 'prose', true);

        $landed = $declared->at('https://cdn.test/master.m3u8');

        self::assertSame('https://cdn.test/master.m3u8', $landed->url);
        self::assertSame(MediaKind::Stream, $landed->kind);
        self::assertSame('p.jpg', $landed->posterUrl);
        self::assertSame('prose', $landed->precedingText);
        self::assertTrue($landed->narrated);
    }
}
