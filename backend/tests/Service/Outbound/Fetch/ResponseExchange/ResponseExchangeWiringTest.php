<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\ResponseExchange;

use App\Service\Outbound\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher;
use App\Service\Outbound\Fetch\ConcurrentRequestEngine;
use App\Service\Outbound\Fetch\Model\ResponseExchangeKind;
use App\Service\Outbound\Fetch\ResponseExchange\ApiResponseExchange;
use App\Service\Outbound\Fetch\ResponseExchange\ResponseExchangeInterface;
use App\Tests\Support\TaggedItemOf;
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
            self::assertSame($kind->value, TaggedItemOf::index($exchange::class));
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
