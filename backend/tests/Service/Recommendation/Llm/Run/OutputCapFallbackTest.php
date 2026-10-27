<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run;

use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\ProviderErrorIdentifiersModel;
use App\Service\Recommendation\Llm\Run\OutputCapFallback;
use App\Service\Recommendation\Run\Model\RequestAdjustmentModel;
use App\Tests\Support\AiProviderSettingsFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class OutputCapFallbackTest extends TestCase
{
    private const string TOO_LARGE = 'That provider refused the request (status 400): max_tokens is too large: 35000. '
        . 'This model supports at most 32768 completion tokens, whereas you provided 35000.';

    public function testOpenAisCapIsRecordedOnTheConnection(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');

        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertEquals(
            RequestAdjustmentModel::outputCap(32_768),
            $this->fallbackFlushing($this->once())->adjust($connection, $rejection),
        );
        self::assertSame(32_768, $connection->getModelMaxOutputTokens());
    }

    public function testTheCapIsReadFromOtherWordingAroundIt(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');

        $rejection = self::tooLarge(
            Response::HTTP_BAD_REQUEST,
            'That provider refused the request (status 400): The output may be at most 16384 tokens long.',
        );

        self::assertEquals(
            RequestAdjustmentModel::outputCap(16_384),
            $this->fallbackFlushing($this->once())->adjust($connection, $rejection),
        );
        self::assertSame(16_384, $connection->getModelMaxOutputTokens());
    }

    public function testTheRefusalsWordsWithoutItsCodeAndParameterAreNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');

        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
        self::assertNull($connection->getModelMaxOutputTokens());
    }

    /** @return iterable<string, array{ProviderErrorIdentifiersModel}> */
    public static function otherIdentifiers(): iterable
    {
        yield 'the parameter refusal' => [new ProviderErrorIdentifiersModel('unsupported_parameter', 'max_tokens')];
        yield 'an invalid value elsewhere' => [new ProviderErrorIdentifiersModel('invalid_value', 'temperature')];
    }

    #[DataProvider('otherIdentifiers')]
    public function testAnotherBadRequestIsNotAbsorbed(ProviderErrorIdentifiersModel $identifiers): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');
        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::TOO_LARGE, $identifiers);

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
        self::assertNull($connection->getModelMaxOutputTokens());
    }

    public function testACapNoLowerThanTheRecordedOneIsNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');
        $connection->recordOutputCap(32_768);

        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
    }

    public function testACapAboveTheRecordedOneIsNotAbsorbedAndKeepsTheLowerOne(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');
        $connection->recordOutputCap(16_384);
        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
        self::assertSame(16_384, $connection->getModelMaxOutputTokens());
    }

    public function testACapBelowTheRecordedOneReplacesIt(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');
        $connection->recordOutputCap(65_536);
        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertEquals(
            RequestAdjustmentModel::outputCap(32_768),
            $this->fallbackFlushing($this->once())->adjust($connection, $rejection),
        );
        self::assertSame(32_768, $connection->getModelMaxOutputTokens());
    }

    public function testARejectionNamingNoCapIsNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');
        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, 'That provider refused the request (status 400): No.');

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
        self::assertNull($connection->getModelMaxOutputTokens());
    }

    public function testACapUnderAnotherStatusIsNotAbsorbed(): void
    {
        self::assertNull($this->fallbackFlushing($this->never())->adjust(
            AiProviderSettingsFactory::buildOn('gpt-4.1-mini'),
            self::tooLarge(Response::HTTP_UNPROCESSABLE_ENTITY, self::TOO_LARGE),
        ));
    }

    public function testACapOfZeroIsNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-4.1-mini');
        $rejection = self::tooLarge(
            Response::HTTP_BAD_REQUEST,
            'That provider refused the request (status 400): This model supports at most 0 completion tokens.',
        );

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
        self::assertNull($connection->getModelMaxOutputTokens());
    }

    private static function tooLarge(int $status, string $message): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(
            $status,
            $message,
            new ProviderErrorIdentifiersModel('invalid_value', 'max_tokens'),
        );
    }

    private function fallbackFlushing(InvocationOrder $flushes): OutputCapFallback
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($flushes)->method('flush');

        return new OutputCapFallback($entityManager);
    }
}
