<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\Service\Settings\InstanceSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;

final class ResettableServicesAreTaggedTest extends KernelTestCase
{
    public function testEveryResettableApplicationServiceIsResetLikeInProduction(): void
    {
        $resettable = [];
        $untagged = [];
        foreach ($this->dumpedContainer()->getElementsByTagName('service') as $service) {
            $class = $service->getAttribute('class');
            if (!str_starts_with($service->getAttribute('id'), 'App\\') || !is_a($class, ResetInterface::class, true)) {
                continue;
            }
            $resettable[] = $class;
            if (!in_array('kernel.reset', $this->tagNames($service), true)) {
                $untagged[] = $class;
            }
        }

        self::assertContains(InstanceSettings::class, $resettable);
        self::assertSame([], $untagged);
    }

    /** @return list<string> */
    private function tagNames(\DOMElement $service): array
    {
        $names = [];
        foreach ($service->getElementsByTagName('tag') as $tag) {
            $names[] = $tag->getAttribute('name');
        }

        return $names;
    }

    private function dumpedContainer(): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load(self::getContainer()->getParameter('debug.container.dump')));

        return $document;
    }
}
