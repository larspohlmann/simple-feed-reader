<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Enum\RequestAdjustment;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\ProviderErrorIdentifiersModel;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use App\Tests\Support\AiProviderSettingsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/** The members' unit tests build the composite by hand; only the compiled container proves which order runs. */
final class RejectedRequestFallbackWiringTest extends KernelTestCase
{
    private const string MAX_TOKENS_REFUSED = "That provider refused the request (status 400): Unsupported parameter: "
        . "'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.";

    private const string TOO_LARGE = 'That provider refused the request (status 400): max_tokens is too large: 35000. '
        . 'This model supports at most 32768 completion tokens, whereas you provided 35000.';

    private const string REASONING_REFUSED = "That provider refused the request (status 400): Unknown parameter: "
        . "'reasoning'.";

    public function testAReasoningModelLearnsItsParameterBeforeItsSuppressionIsDropped(): void
    {
        $fallback = $this->fallback();
        $connection = $this->suppressingConnectionOn('gpt-5.4-nano');

        self::assertSame(
            RequestAdjustment::TokenParameter,
            $fallback->adjust($connection, $this->maxTokensRefusal())?->setting,
        );
        self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
        self::assertFalse($connection->refusesSuppressedReasoning());

        self::assertSame(
            RequestAdjustment::ReasoningSuppression,
            $fallback->adjust($connection, $this->reasoningRefusal())?->setting,
        );
        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    public function testACappedModelLearnsItsCapBeforeItsSuppressionIsDropped(): void
    {
        $fallback = $this->fallback();
        $connection = $this->suppressingConnectionOn('gpt-4.1-mini');

        self::assertSame(
            RequestAdjustment::OutputCap,
            $fallback->adjust($connection, $this->tooLargeRefusal())?->setting,
        );
        self::assertSame(32_768, $connection->getModelMaxOutputTokens());
        self::assertFalse($connection->refusesSuppressedReasoning());

        self::assertSame(
            RequestAdjustment::ReasoningSuppression,
            $fallback->adjust($connection, $this->reasoningRefusal())?->setting,
        );
        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    private function fallback(): RejectedRequestFallbackInterface
    {
        self::bootKernel();
        $fallback = self::getContainer()->get(RejectedRequestFallbackInterface::class);
        self::assertInstanceOf(RejectedRequestFallbackInterface::class, $fallback);

        return $fallback;
    }

    private function suppressingConnectionOn(string $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::buildOn($model);
        self::assertTrue($connection->suppressesReasoning());

        return $connection;
    }

    private function maxTokensRefusal(): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(
            Response::HTTP_BAD_REQUEST,
            self::MAX_TOKENS_REFUSED,
            new ProviderErrorIdentifiersModel('unsupported_parameter', 'max_tokens'),
        );
    }

    private function tooLargeRefusal(): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(
            Response::HTTP_BAD_REQUEST,
            self::TOO_LARGE,
            new ProviderErrorIdentifiersModel('invalid_value', 'max_tokens'),
        );
    }

    private function reasoningRefusal(): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, self::REASONING_REFUSED);
    }
}
