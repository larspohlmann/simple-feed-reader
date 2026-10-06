<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\ModelCatalog;

use App\Service\Ai\ModelCatalog\CompositeModelCatalog;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;
use App\Service\Ai\ModelCatalog\OpenAiCompatibleCatalog;
use App\Service\Ai\ModelCatalog\SystemOneCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Only the compiled container proves the tag and the fallback: the listing is a member, the probe is not. */
final class ModelCatalogWiringTest extends KernelTestCase
{
    public function testEveryCallerGetsTheCompositeOverTheListingWithTheProbeAsItsFallback(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(ModelCatalogInterface::class);
        self::assertInstanceOf(CompositeModelCatalog::class, $catalog);

        /** @var iterable<object> $members */
        $members = (new \ReflectionProperty(CompositeModelCatalog::class, 'catalogs'))->getValue($catalog);
        $classes = [];
        foreach ($members as $member) {
            $classes[] = $member::class;
        }

        self::assertSame([OpenAiCompatibleCatalog::class], $classes);
        self::assertInstanceOf(
            SystemOneCatalog::class,
            (new \ReflectionProperty(CompositeModelCatalog::class, 'systemOneFallback'))->getValue($catalog),
        );
    }
}
