<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\Factory\ScoringStateFactory;
use App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocolResolver;
use App\Tests\Support\StubSystemOneClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ScoringProtocolResolverTest extends TestCase
{
    public function testTheImplementationComesFromTheLocatorUnderItsProtocolsValue(): void
    {
        $systemOne = new SystemOneProtocol(
            new ScoringBatchPacker(),
            new SystemOneRequestFactory(new ScoringStateFactory()),
            new StubSystemOneClient(),
        );
        $resolver = new ScoringProtocolResolver(
            new ServiceLocator(['system_one' => static fn (): SystemOneProtocol => $systemOne]),
        );

        self::assertSame($systemOne, $resolver->protocolOf(ScoringProtocol::SystemOne));
    }

    public function testAProtocolWithoutAnImplementationIsAWiringError(): void
    {
        $resolver = new ScoringProtocolResolver(new ServiceLocator([]));

        try {
            $resolver->protocolOf(ScoringProtocol::SystemOne);
            self::fail('A missing protocol must not resolve.');
        } catch (\LogicException $exception) {
            self::assertSame('No scoring protocol is wired for "system_one".', $exception->getMessage());
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception->getPrevious());
        }
    }
}
