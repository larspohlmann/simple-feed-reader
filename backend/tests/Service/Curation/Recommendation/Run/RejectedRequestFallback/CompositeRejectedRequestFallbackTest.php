<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\ProviderErrorIdentifiersModel;
use App\Service\Recommendation\Run\Model\RequestAdjustmentModel;
use App\Service\Recommendation\Run\RejectedRequestFallback\CompositeRejectedRequestFallback;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class CompositeRejectedRequestFallbackTest extends TestCase
{
    public function testTheFirstMemberThatAdjustsEndsTheChainAndItsAdjustmentIsReturned(): void
    {
        $calls = new \ArrayObject();
        $outputCap = RequestAdjustmentModel::outputCap(32_768);
        $composite = new CompositeRejectedRequestFallback(
            new RecordingFallback('tokenParameter', null, $calls),
            new RecordingFallback('outputCap', $outputCap, $calls),
            new RecordingFallback('suppressedReasoning', RequestAdjustmentModel::reasoningSuppressionDropped(), $calls),
        );

        self::assertSame($outputCap, $composite->adjust($this->connection(), $this->rejection()));
        self::assertSame(['tokenParameter', 'outputCap'], $calls->getArrayCopy());
    }

    public function testNoMemberAdjustingLeavesTheRejectionStanding(): void
    {
        $calls = new \ArrayObject();
        $composite = new CompositeRejectedRequestFallback(
            new RecordingFallback('tokenParameter', null, $calls),
            new RecordingFallback('outputCap', null, $calls),
            new RecordingFallback('suppressedReasoning', null, $calls),
        );

        self::assertNull($composite->adjust($this->connection(), $this->rejection()));
        self::assertSame(['tokenParameter', 'outputCap', 'suppressedReasoning'], $calls->getArrayCopy());
    }

    private function connection(): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(
            new User('reader@example.test', new \DateTimeImmutable('2026-10-26 09:00:00')),
        );
    }

    private function rejection(): ProviderRejectedRequestException
    {
        return new ProviderRejectedRequestException(
            Response::HTTP_BAD_REQUEST,
            'No.',
            new ProviderErrorIdentifiersModel('invalid_value', 'max_tokens'),
        );
    }
}
