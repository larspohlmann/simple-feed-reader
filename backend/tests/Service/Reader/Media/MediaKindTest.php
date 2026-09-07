<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaKind;
use PHPUnit\Framework\TestCase;

final class MediaKindTest extends TestCase
{
    public function testOnlyAudioIsNotALeadVisual(): void
    {
        self::assertFalse(MediaKind::Audio->readsAsLeadVisual());
        self::assertTrue(MediaKind::Video->readsAsLeadVisual());
        self::assertTrue(MediaKind::Stream->readsAsLeadVisual());
        self::assertTrue(MediaKind::Embed->readsAsLeadVisual());
    }

    public function testOnlyVideoAndStreamPlayInAVideoElement(): void
    {
        self::assertTrue(MediaKind::Video->isVideo());
        self::assertTrue(MediaKind::Stream->isVideo());
        self::assertFalse(MediaKind::Audio->isVideo());
        self::assertFalse(MediaKind::Embed->isVideo());
    }
}
