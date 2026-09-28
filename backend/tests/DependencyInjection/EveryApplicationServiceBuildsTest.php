<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EveryApplicationServiceBuildsTest extends KernelTestCase
{
    private const string EXCLUDED = 'has been excluded in "config/services.yaml"';

    // Data built with `new`, never injected, that takes an excluded Dto/ or Model/ type until a later PR moves it.
    private const array BUILT_WITH_NEW = [
        'App\\Service\\Ai\\Completion\\ConcurrentCompletion',
        'App\\Service\\Backup\\BackupPartGuard',
        'App\\Service\\Backup\\BackupPartWalk',
        'App\\Service\\Mail\\Transport\\CurlSmtpTransport',
    ];

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
        $failures = [];
        foreach ($this->dumpedContainer()->getElementsByTagName('service') as $service) {
            foreach ($service->getElementsByTagName('tag') as $tag) {
                $error = $tag->getAttribute('message');
                $needsAnExcludedType = 'container.error' === $tag->getAttribute('name')
                    && str_contains($error, self::EXCLUDED);
                if ($needsAnExcludedType && !\in_array($service->getAttribute('id'), self::BUILT_WITH_NEW, true)) {
                    $failures[$service->getAttribute('id')] = $error;
                }
            }
        }

        self::assertSame([], $failures);
    }

    private function dumpedContainer(): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load(self::getContainer()->getParameter('debug.container.dump')));

        return $document;
    }
}
