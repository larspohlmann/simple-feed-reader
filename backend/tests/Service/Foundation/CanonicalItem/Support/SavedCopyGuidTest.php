<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\CanonicalItem\Support;

use App\Service\Foundation\CanonicalItem\Support\SavedCopyGuid;
use PHPUnit\Framework\TestCase;

final class SavedCopyGuidTest extends TestCase
{
    public function testABuiltGuidStripsBackToItsOriginal(): void
    {
        self::assertSame('https://lemmy.example/post/7#42', SavedCopyGuid::build('https://lemmy.example/post/7', 42));
        self::assertSame(
            'https://lemmy.example/post/7',
            SavedCopyGuid::stripSuffix(SavedCopyGuid::build('https://lemmy.example/post/7', 42)),
        );
    }
}
