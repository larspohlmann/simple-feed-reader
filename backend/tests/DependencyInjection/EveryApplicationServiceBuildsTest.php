<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\Service\Refresh\FeedBodyParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EveryApplicationServiceBuildsTest extends KernelTestCase
{
    private const string EXCLUDED = 'has been excluded in "config/services.yaml"';
    private const int FEWEST_APPLICATION_SERVICES = 1000;

    public function testTheContainerBuildsEveryApplicationServiceItExposes(): void
    {
        $container = self::getContainer();
        $failures = [];
        $built = 0;
        foreach ($container->getServiceIds() as $id) {
            if (!str_starts_with($id, 'App\\')) {
                continue;
            }
            try {
                $container->get($id);
                ++$built;
            } catch (\Throwable $failure) {
                $failures[$id] = $failure->getMessage();
            }
        }

        self::assertSame([], $failures);
        self::assertGreaterThan(0, $built);
    }

    public function testNoServiceNeedsAClassTheContainerExcludes(): void
    {
        $services = $this->dumpedContainer()->getElementsByTagName('service');

        $applicationIds = self::applicationServiceIds($services);
        self::assertGreaterThanOrEqual(self::FEWEST_APPLICATION_SERVICES, \count($applicationIds));
        self::assertContains(FeedBodyParser::class, $applicationIds);
        self::assertSame([], self::excludedClassFailures($services));
    }

    /**
     * @param \DOMNodeList<\DOMElement> $services
     *
     * @return list<string>
     */
    private static function applicationServiceIds(\DOMNodeList $services): array
    {
        $ids = [];
        foreach ($services as $service) {
            if (str_starts_with($service->getAttribute('id'), 'App\\')) {
                $ids[] = $service->getAttribute('id');
            }
        }

        return $ids;
    }

    /**
     * @param \DOMNodeList<\DOMElement> $services
     *
     * @return array<string, string>
     */
    private static function excludedClassFailures(\DOMNodeList $services): array
    {
        $failures = [];
        foreach ($services as $service) {
            foreach ($service->getElementsByTagName('tag') as $tag) {
                $error = $tag->getAttribute('message');
                if ('container.error' === $tag->getAttribute('name') && str_contains($error, self::EXCLUDED)) {
                    $failures[$service->getAttribute('id')] = $error;
                }
            }
        }

        return $failures;
    }

    private function dumpedContainer(): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load(self::getContainer()->getParameter('debug.container.dump')));

        return $document;
    }
}
