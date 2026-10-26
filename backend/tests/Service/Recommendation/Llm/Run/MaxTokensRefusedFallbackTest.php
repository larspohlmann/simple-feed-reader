<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\ProviderErrorIdentifiersModel;
use App\Service\Recommendation\Llm\Run\MaxTokensRefusedFallback;
use App\Tests\Support\AiProviderSettingsFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class MaxTokensRefusedFallbackTest extends TestCase
{
    private const string REFUSAL = "That provider refused the request (status 400): Unsupported parameter: "
        . "'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.";

    public function testOpenAisRefusalOfMaxTokensSwitchesTheConnection(): void
    {
        $connection = $this->connectionOn('gpt-5.4-nano');

        $rejection = self::refusal(Response::HTTP_BAD_REQUEST, self::REFUSAL);

        self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, $rejection));
        self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
    }

    public function testTheRefusalIsRecognisedWhateverItsWording(): void
    {
        $connection = $this->connectionOn('gpt-5.4-nano');

        $rejection = self::refusal(Response::HTTP_BAD_REQUEST, 'That provider refused the request (status 400): No.');

        self::assertTrue($this->fallbackFlushing($this->once())->absorbs($connection, $rejection));
        self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
    }

    public function testTheRefusalsWordsWithoutItsCodeAndParameterAreNotAbsorbed(): void
    {
        $connection = $this->connectionOn('gpt-5.4-nano');

        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::REFUSAL);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
        self::assertSame(TokenLimitParameter::MaxTokens, $connection->getTokenLimitParameter());
    }

    /** @return iterable<string, array{ProviderErrorIdentifiersModel}> */
    public static function otherIdentifiers(): iterable
    {
        yield 'the cap refusal' => [new ProviderErrorIdentifiersModel('invalid_value', 'max_tokens')];
        yield 'another parameter' => [new ProviderErrorIdentifiersModel('unsupported_parameter', 'reasoning')];
    }

    #[DataProvider('otherIdentifiers')]
    public function testAnotherBadRequestIsLeftToTheNextFallback(ProviderErrorIdentifiersModel $identifiers): void
    {
        $connection = $this->connectionOn('gpt-5.4-nano');
        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::REFUSAL, $identifiers);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
        self::assertSame(TokenLimitParameter::MaxTokens, $connection->getTokenLimitParameter());
    }

    public function testTheSameRefusalUnderAnotherStatusIsNotAbsorbed(): void
    {
        self::assertFalse($this->fallbackFlushing($this->never())->absorbs(
            $this->connectionOn('gpt-5.4-nano'),
            self::refusal(Response::HTTP_UNPROCESSABLE_ENTITY, self::REFUSAL),
        ));
    }

    public function testAConnectionAlreadyOnMaxCompletionTokensIsNotAbsorbedAgain(): void
    {
        $connection = $this->connectionOn('gpt-5.4-nano');
        $connection->recordMaxTokensRefused();

        $rejection = self::refusal(Response::HTTP_BAD_REQUEST, self::REFUSAL);

        self::assertFalse($this->fallbackFlushing($this->never())->absorbs($connection, $rejection));
    }

    private static function refusal(int $status, string $message): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(
            $status,
            $message,
            new ProviderErrorIdentifiersModel('unsupported_parameter', 'max_tokens'),
        );
    }

    private function fallbackFlushing(InvocationOrder $flushes): MaxTokensRefusedFallback
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($flushes)->method('flush');

        return new MaxTokensRefusedFallback($entityManager);
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
