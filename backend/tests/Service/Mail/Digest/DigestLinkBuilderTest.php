<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Settings\PublicBaseUrl;
use PHPUnit\Framework\TestCase;

final class DigestLinkBuilderTest extends TestCase
{
    private function builderFor(string $base): DigestLinkBuilder
    {
        $publicBaseUrl = new class ($base) implements PublicBaseUrl {
            public function __construct(private readonly string $base)
            {
            }

            public function get(): string
            {
                return $this->base;
            }
        };

        return new DigestLinkBuilder($publicBaseUrl);
    }

    public function testEntryUrlUsesBareId(): void
    {
        self::assertSame(
            'https://lars-pohlmann.de/reader/?entry=514',
            $this->builderFor('https://lars-pohlmann.de/reader')->entryUrl(514),
        );
    }

    public function testATrailingSlashOnTheBaseIsNormalised(): void
    {
        self::assertSame(
            'https://lars-pohlmann.de/reader/?entry=514',
            $this->builderFor('https://lars-pohlmann.de/reader/')->entryUrl(514),
        );
    }

    public function testSavedSearchUrlEncodesTermAndWholeWordSpace(): void
    {
        $builder = $this->builderFor('https://lars-pohlmann.de/reader');

        self::assertSame('https://lars-pohlmann.de/reader/?q=rust', $builder->savedSearchUrl('rust', false));
        self::assertSame('https://lars-pohlmann.de/reader/?q=rust%20', $builder->savedSearchUrl('rust', true));
    }
}
