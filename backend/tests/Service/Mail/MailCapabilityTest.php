<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailCapability;
use PHPUnit\Framework\TestCase;

final class MailCapabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideFlags(): iterable
    {
        yield 'empty means enabled' => ['', true];
        yield 'zero means enabled' => ['0', true];
        yield 'false means enabled' => ['false', true];
        yield 'one disables' => ['1', false];
        yield 'true disables' => ['true', false];
        yield 'yes disables' => ['yes', false];
        yield 'on disables' => ['on', false];
        yield 'case-insensitive' => ['TRUE', false];
        yield 'whitespace tolerated' => [' 1 ', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideFlags')]
    public function testReadsTheDisableFlag(string $flag, bool $expectedEnabled): void
    {
        self::assertSame($expectedEnabled, (new MailCapability($flag))->isEnabled());
    }
}
