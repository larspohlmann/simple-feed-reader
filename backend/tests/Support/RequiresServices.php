<?php

declare(strict_types=1);

namespace App\Tests\Support;

trait RequiresServices
{
    /**
     * @template T of object
     *
     * @param class-string<T> $serviceClass
     *
     * @return T
     */
    private function requireFromContainer(string $serviceClass): object
    {
        $service = self::getContainer()->get($serviceClass);
        self::assertInstanceOf($serviceClass, $service);

        return $service;
    }
}
