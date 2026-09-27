<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Service\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testTwelveCharactersAreLongEnough(): void
    {
        self::assertTrue(PasswordPolicy::isLongEnough('twelve-chars'));
    }

    public function testElevenCharactersAreNot(): void
    {
        self::assertFalse(PasswordPolicy::isLongEnough('eleven-char'));
    }

    public function testTheLengthCountsCharactersNotBytes(): void
    {
        self::assertTrue(PasswordPolicy::isLongEnough(str_repeat('ä', 12)));
        self::assertFalse(PasswordPolicy::isLongEnough(str_repeat('ä', 11)));
    }
}
