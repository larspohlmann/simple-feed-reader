<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run;

use App\Enum\RequestAdjustment;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\ProviderErrorIdentifiersModel;
use App\Service\Recommendation\Llm\Run\MaxTokensRefusedFallback;
use App\Service\Recommendation\Run\Model\RequestAdjustmentModel;
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
        $connection = AiProviderSettingsFactory::buildOn('gpt-5.4-nano');

        $rejection = self::refusal(Response::HTTP_BAD_REQUEST, self::REFUSAL);

        self::assertAdjusted(
            RequestAdjustment::TokenParameter,
            'max_completion_tokens',
            $this->fallbackFlushing($this->once())->adjust($connection, $rejection),
        );
        self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
    }

    public function testTheRefusalIsRecognisedWhateverItsWording(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-5.4-nano');

        $rejection = self::refusal(Response::HTTP_BAD_REQUEST, 'That provider refused the request (status 400): No.');

        self::assertAdjusted(
            RequestAdjustment::TokenParameter,
            'max_completion_tokens',
            $this->fallbackFlushing($this->once())->adjust($connection, $rejection),
        );
        self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
    }

    public function testTheRefusalsWordsWithoutItsCodeAndParameterAreNotAbsorbed(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-5.4-nano');

        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::REFUSAL);

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
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
        $connection = AiProviderSettingsFactory::buildOn('gpt-5.4-nano');
        $rejection = new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::REFUSAL, $identifiers);

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
        self::assertSame(TokenLimitParameter::MaxTokens, $connection->getTokenLimitParameter());
    }

    public function testTheSameRefusalUnderAnotherStatusIsNotAbsorbed(): void
    {
        self::assertNull($this->fallbackFlushing($this->never())->adjust(
            AiProviderSettingsFactory::buildOn('gpt-5.4-nano'),
            self::refusal(Response::HTTP_UNPROCESSABLE_ENTITY, self::REFUSAL),
        ));
    }

    public function testAConnectionAlreadyOnMaxCompletionTokensIsNotAbsorbedAgain(): void
    {
        $connection = AiProviderSettingsFactory::buildOn('gpt-5.4-nano');
        $connection->recordMaxTokensRefused();

        $rejection = self::refusal(Response::HTTP_BAD_REQUEST, self::REFUSAL);

        self::assertNull($this->fallbackFlushing($this->never())->adjust($connection, $rejection));
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

    private static function assertAdjusted(
        RequestAdjustment $setting,
        ?string $value,
        ?RequestAdjustmentModel $adjustment,
    ): void {
        self::assertNotNull($adjustment);
        self::assertSame($setting, $adjustment->setting);
        self::assertSame($value, $adjustment->value);
    }
}
