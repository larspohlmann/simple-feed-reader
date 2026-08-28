<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestLinkBuilder;
use PHPUnit\Framework\TestCase;

final class DigestLinkBuilderTest extends TestCase
{
    public function testEntryUrlUsesBareId(): void
    {
        $builder = new DigestLinkBuilder('https://lars-pohlmann.de/reader/');
        self::assertSame('https://lars-pohlmann.de/reader/?entry=514', $builder->entryUrl(514));
    }

    public function testSavedSearchUrlEncodesTermAndWholeWordSpace(): void
    {
        $builder = new DigestLinkBuilder('https://lars-pohlmann.de/reader');
        self::assertSame('https://lars-pohlmann.de/reader/?q=rust', $builder->savedSearchUrl('rust', false));
        self::assertSame('https://lars-pohlmann.de/reader/?q=rust%20', $builder->savedSearchUrl('rust', true));
    }
}
