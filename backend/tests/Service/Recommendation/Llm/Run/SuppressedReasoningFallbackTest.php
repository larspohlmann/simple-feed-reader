<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
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
        $connection = $this->connectionOn('qwen3-14b');

        self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, $this->rejection($status)));
        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    #[DataProvider('otherRejectingStatuses')]
    public function testARejectionThatNoParameterEarnsIsNotAbsorbed(int $status): void
    {
        $connection = $this->connectionOn('qwen3-14b');

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $this->rejection($status)));
        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testARequestThatDidNotSuppressReasoningIsNotAbsorbed(): void
    {
        $connection = $this->connectionOn('qwen3-14b');
        $connection->setSuppressReasoning(false);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $this->rejection(400)));
        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testARejectionOfTheResendWithoutSuppressionIsNotAbsorbed(): void
    {
        $connection = $this->connectionOn('qwen3-14b');
        $connection->recordSuppressionRefused();

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $this->rejection(400)));
    }

    public function testARejectionOnAnEngineWithoutASuppressReasoningSettingIsNotAbsorbed(): void
    {
        $connection = $this->connectionOn('jev-latest');

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

    private function connectionOn(string $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('reader@example.test', new \DateTimeImmutable('2026-10-05 09:00:00')),
        );
        $connection->chooseModel(new ModelDescriptor($model, 32768), new \DateTimeImmutable('2026-10-05 10:00:00'));

        return $connection;
    }
}
