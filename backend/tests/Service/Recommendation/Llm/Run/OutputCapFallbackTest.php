<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\ProviderErrorIdentifiersModel;
use App\Service\Recommendation\Llm\Run\OutputCapFallback;
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
        $connection = $this->connectionOn('gpt-4.1-mini');

        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, $rejection));
        self::assertSame(32_768, $connection->getModelMaxOutputTokens());
    }

    public function testTheCapIsReadFromOtherWordingAroundIt(): void
    {
        $connection = $this->connectionOn('gpt-4.1-mini');

        $rejection = self::tooLarge(
            Response::HTTP_BAD_REQUEST,
            'That provider refused the request (status 400): The output may be at most 16384 tokens long.',
        );

        self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, $rejection));
        self::assertSame(16_384, $connection->getModelMaxOutputTokens());
    }

    public function testTheRefusalsWordsWithoutItsCodeAndParameterAreNotAbsorbed(): void
    {
        $connection = $this->connectionOn('gpt-4.1-mini');

        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
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
        $connection = $this->connectionOn('gpt-4.1-mini');
        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::TOO_LARGE, $identifiers);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
        self::assertNull($connection->getModelMaxOutputTokens());
    }

    public function testACapNoLowerThanTheRecordedOneIsNotAbsorbed(): void
    {
        $connection = $this->connectionOn('gpt-4.1-mini');
        $connection->recordOutputCap(32_768);

        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
    }

    public function testACapBelowTheRecordedOneReplacesIt(): void
    {
        $connection = $this->connectionOn('gpt-4.1-mini');
        $connection->recordOutputCap(65_536);
        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, self::TOO_LARGE);

        self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, $rejection));
        self::assertSame(32_768, $connection->getModelMaxOutputTokens());
    }

    public function testARejectionNamingNoCapIsNotAbsorbed(): void
    {
        $connection = $this->connectionOn('gpt-4.1-mini');
        $rejection = self::tooLarge(Response::HTTP_BAD_REQUEST, 'That provider refused the request (status 400): No.');

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
        self::assertNull($connection->getModelMaxOutputTokens());
    }

    public function testACapUnderAnotherStatusIsNotAbsorbed(): void
    {
        self::assertFalse($this->fallbackFlushing($this->never())->absorbs(
            $this->connectionOn('gpt-4.1-mini'),
            self::tooLarge(Response::HTTP_UNPROCESSABLE_ENTITY, self::TOO_LARGE),
        ));
    }

    public function testACapOfZeroIsNotAbsorbed(): void
    {
        $connection = $this->connectionOn('gpt-4.1-mini');
        $rejection = self::tooLarge(
            Response::HTTP_BAD_REQUEST,
            'That provider refused the request (status 400): This model supports at most 0 completion tokens.',
        );

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
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

    private function connectionOn(string $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('reader@example.test', new \DateTimeImmutable('2026-10-26 09:00:00')),
        );
        $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-10-26 10:00:00'));

        return $connection;
    }
}
