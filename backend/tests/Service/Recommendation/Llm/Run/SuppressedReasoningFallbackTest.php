<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Llm\Run\SuppressedReasoningFallback;
use App\Tests\Support\AiProviderSettingsFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SuppressedReasoningFallbackTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function statusesARefusedParameterEarns(): iterable
    {
        yield 'bad request' => [400];
        yield 'unprocessable' => [422];
    }

    /** @return iterable<string, array{int}> */
    public static function otherRejectingStatuses(): iterable
    {
        yield 'not found' => [404];
        yield 'payment required' => [402];
    }

    #[DataProvider('statusesARefusedParameterEarns')]
    public function testARejectedSuppressingRequestIsRecordedAsTheModelRefusingIt(int $status): void
    {
        $connection = AiProviderSettingsFactory::buildOn('qwen3-14b');

        self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, $this->rejection($status)));
        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    #[DataProvider('otherRejectingStatuses')]
    public function testARejectionThatNoParameterEarnsIsNotAbsorbed(int $status): void
    {
        $connection = AiProviderSettingsFactory::buildOn('qwen3-14b');

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $this->rejection($status)));
        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testARequestThatDidNotSuppressReasoningIsNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('qwen3-14b');
        $connection->setSuppressReasoning(false);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $this->rejection(400)));
        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testARejectionOfTheResendWithoutSuppressionIsNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('qwen3-14b');
        $connection->recordSuppressionRefused();

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $this->rejection(400)));
    }

    public function testARejectionOnAnEngineWithoutASuppressReasoningSettingIsNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('jev-latest', ScoringProtocol::SystemOne);

        self::assertTrue($connection->suppressesReasoning());
        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $this->rejection(400)));
        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    private function fallbackFlushing(InvocationOrder $flushes): SuppressedReasoningFallback
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($flushes)->method('flush');

        return new SuppressedReasoningFallback(
            new RecommendationEngineResolver(new ServiceLocator([])),
            $entityManager,
        );
    }

    private function rejection(int $status): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(
            $status,
            \sprintf('That provider refused the request (status %d): No.', $status),
        );
    }
}
