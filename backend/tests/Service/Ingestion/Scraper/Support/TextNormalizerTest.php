<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Scraper\Support;

use App\Service\Ingestion\Scraper\Support\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testStripsSoftHyphensAndCollapsesWhitespace(): void
    {
        self::assertSame(
            'Gesundheitsministerin Warken',
            TextNormalizer::normalize("Gesundheits\u{00AD}ministerin\n   Warken  ")
        );
    }

    public function testEmptyBecomesEmptyString(): void
    {
        self::assertSame('', TextNormalizer::normalize("\u{00AD} \n "));
    }
}
