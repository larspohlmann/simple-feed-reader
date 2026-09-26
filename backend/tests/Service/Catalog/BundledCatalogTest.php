<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BundledCatalogTest extends KernelTestCase
{
    public function testTheShippedDocumentIsSummarisedWithoutImportingIt(): void
    {
        $bundled = self::getContainer()->get(BundledCatalog::class);
        self::assertInstanceOf(BundledCatalog::class, $bundled);
        $document = $bundled->document();

        $summary = $bundled->summary();

        self::assertTrue($summary->available);
        self::assertSame(\count($document->categories), $summary->categories);
        self::assertSame($document->feedCount(), $summary->feeds);
        self::assertGreaterThan(0, $summary->feeds);
    }

    public function testAMissingDocumentSummarisesAsUnavailable(): void
    {
        $parser = self::getContainer()->get(CatalogDocument::class);
        self::assertInstanceOf(CatalogDocument::class, $parser);
        $bundled = new BundledCatalog($parser, sys_get_temp_dir() . '/no-catalog-' . bin2hex(random_bytes(6)));

        $summary = $bundled->summary();

        self::assertFalse($summary->available);
        self::assertSame(0, $summary->categories);
        self::assertSame(0, $summary->feeds);
    }
}
