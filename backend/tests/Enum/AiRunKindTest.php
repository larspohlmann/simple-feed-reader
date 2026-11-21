<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\AiRunKind;
use PHPUnit\Framework\TestCase;

final class AiRunKindTest extends TestCase
{
    public function testTieRankOrdersForYouProfileStory(): void
    {
        self::assertSame(2, AiRunKind::ForYou->tieRank());
        self::assertSame(1, AiRunKind::Profile->tieRank());
        self::assertSame(0, AiRunKind::Story->tieRank());
    }

    public function testValuesAreTheCursorSpellings(): void
    {
        self::assertSame('for_you', AiRunKind::ForYou->value);
        self::assertSame('profile', AiRunKind::Profile->value);
        self::assertSame('story', AiRunKind::Story->value);
    }
}
