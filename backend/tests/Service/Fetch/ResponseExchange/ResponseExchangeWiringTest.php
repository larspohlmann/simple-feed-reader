<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\ResponseExchange;

use App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Fetch\ResponseExchange\ApiResponseExchange;
use App\Service\Fetch\ResponseExchange\ResponseExchangeInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResponseExchangeWiringTest extends KernelTestCase
{
    public function testTheEngineReadsEveryKindOfAnswerThroughItsOwnExchange(): void
    {
        $exchanges = self::exchanges();
        foreach (ResponseExchangeKind::cases() as $kind) {
            $exchange = $exchanges->get($kind->value);
            self::assertInstanceOf(ResponseExchangeInterface::class, $exchange);
            self::assertSame($kind->value, $exchange::kind());
        }
    }

    public function testTheEngineReadsAnApiAnswerThroughTheApiExchange(): void
    {
        self::assertInstanceOf(ApiResponseExchange::class, self::exchanges()->get(ResponseExchangeKind::Api->value));
    }

    private static function exchanges(): ContainerInterface
    {
        self::bootKernel();
        $fetcher = self::getContainer()->get(ConcurrentFeedFetcher::class);
        $engine = (new \ReflectionProperty(ConcurrentFeedFetcher::class, 'engine'))->getValue($fetcher);
        self::assertInstanceOf(ConcurrentRequestEngine::class, $engine);
        $exchanges = (new \ReflectionProperty(ConcurrentRequestEngine::class, 'exchanges'))->getValue($engine);
        self::assertInstanceOf(ContainerInterface::class, $exchanges);

        return $exchanges;
    }
}
