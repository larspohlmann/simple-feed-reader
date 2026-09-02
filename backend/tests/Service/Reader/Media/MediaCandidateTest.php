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
        );

        $completed = $declared->completedBy($scanned);

        self::assertSame('https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $completed->posterUrl);
        self::assertSame('Watch on YouTube', $completed->label);
        self::assertSame('The prose the player followed.', $completed->precedingText);
        self::assertSame(MediaKind::Embed, $completed->kind);
    }

    public function testKeepsEverythingItAlreadyHas(): void
    {
        $full = new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/a.jpg', 'A', 'Prose A.');
        $other = new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/b.jpg', 'B', 'Prose B.');

        $completed = $full->completedBy($other);

        self::assertSame('https://x.test/a.jpg', $completed->posterUrl);
        self::assertSame('A', $completed->label);
        self::assertSame('Prose A.', $completed->precedingText);
    }
}
