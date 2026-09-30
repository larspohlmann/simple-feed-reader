<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\Kernel;
use App\Service\Worker\Handler\RefreshDueFeedsHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TestContainerTagParityTest extends TestCase
{
    private const int FEWEST_SHARED_SERVICES = 1000;

    public function testEveryApplicationServiceCarriesItsProductionTagsInTheTestContainer(): void
    {
        $production = self::tagNamesByApplicationService('prod');
        $test = self::tagNamesByApplicationService('test');

        self::assertContains('messenger.message_handler', $production[RefreshDueFeedsHandler::class] ?? []);
        self::assertGreaterThanOrEqual(self::FEWEST_SHARED_SERVICES, \count(array_intersect_key($production, $test)));
        self::assertSame([], self::tagDifferences($production, $test));
    }

    /**
     * @param array<string, list<string>> $production
     * @param array<string, list<string>> $test
     *
     * @return array<string, array{production: list<string>, test: list<string>}>
     */
    private static function tagDifferences(array $production, array $test): array
    {
        $differences = [];
        foreach (array_intersect_key($production, $test) as $id => $productionTags) {
            if ($productionTags !== $test[$id]) {
                $differences[$id] = ['production' => $productionTags, 'test' => $test[$id]];
            }
        }

        return $differences;
    }

    /** @return array<string, list<string>> */
    private static function tagNamesByApplicationService(string $environment): array
    {
        $tagNames = [];
        foreach (self::compiledContainer($environment)->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'App\\')) {
                continue;
            }
            $names = array_map(strval(...), array_keys($definition->getTags()));
            sort($names);
            $tagNames[$id] = $names;
        }

        return $tagNames;
    }

    private static function compiledContainer(string $environment): ContainerBuilder
    {
        // Not debug: a debug compile rewrites the container dump and config/reference.php that parallel runs share.
        $kernel = new class ($environment, debug: false) extends Kernel {
            public function compileWithEveryDefinition(): ContainerBuilder
            {
                $this->initializeBundles();
                $container = $this->buildContainer();
                // No removal, as in debug:container: an unused private service would drop out of one side unseen.
                $container->getCompilerPassConfig()->setRemovingPasses([]);
                $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
                $container->compile();

                return $container;
            }
        };

        return $kernel->compileWithEveryDefinition();
    }
}
