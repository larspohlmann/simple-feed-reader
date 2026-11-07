<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Model;

use App\Service\Reader\Media\Model\AudioClipModel;
use PHPUnit\Framework\TestCase;

final class AudioClipModelTest extends TestCase
{
    public function testReadsWholePositiveSeconds(): void
    {
        self::assertSame(2871, AudioClipModel::secondsFrom('2871'));
        self::assertSame(2871, AudioClipModel::secondsFrom(' 2871 '));
    }

    public function testRejectsAnythingThatIsNotAPositiveWholeNumber(): void
    {
        self::assertNull(AudioClipModel::secondsFrom(null));
        self::assertNull(AudioClipModel::secondsFrom(''));
        self::assertNull(AudioClipModel::secondsFrom('0'));
        self::assertNull(AudioClipModel::secondsFrom('-5'));
        self::assertNull(AudioClipModel::secondsFrom('47:51'));
        self::assertNull(AudioClipModel::secondsFrom('12.5'));
        self::assertNull(AudioClipModel::secondsFrom('PT47M51S'));
    }
}
