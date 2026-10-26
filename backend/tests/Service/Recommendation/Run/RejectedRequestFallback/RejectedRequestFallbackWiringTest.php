<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use App\Tests\Support\AiProviderSettingsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::MAX_TOKENS_REFUSED)));
        self::assertSame(TokenLimitParameter::MaxCompletionTokens, $connection->getTokenLimitParameter());
        self::assertFalse($connection->refusesSuppressedReasoning());

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::REASONING_REFUSED)));
        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    public function testACappedModelLearnsItsCapBeforeItsSuppressionIsDropped(): void
    {
        $fallback = $this->fallback();
        $connection = $this->suppressingConnectionOn('gpt-4.1-mini');

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::TOO_LARGE)));
        self::assertSame(32_768, $connection->getModelMaxOutputTokens());
        self::assertFalse($connection->refusesSuppressedReasoning());

        self::assertTrue($fallback->absorbs($connection, $this->rejection(self::REASONING_REFUSED)));
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
        $connection = AiProviderSettingsFactory::build(
            new User('fallback-wiring@example.test', new \DateTimeImmutable('2026-10-26 09:00:00')),
        );
        $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-10-26 09:05:00'));
        self::assertTrue($connection->suppressesReasoning());

        return $connection;
    }

    private function rejection(string $message): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(400, $message);
    }
}
