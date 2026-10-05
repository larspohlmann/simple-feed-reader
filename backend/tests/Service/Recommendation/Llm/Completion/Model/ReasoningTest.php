<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Completion\Model;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class ReasoningTest extends TestCase
{
    public function testAConnectionSuppressesReasoningByDefault(): void
    {
        self::assertSame(Reasoning::Suppressed, Reasoning::preferredBy($this->connection()));
    }

    public function testAConnectionThatAllowsReasoningLetsTheCallReason(): void
    {
        $connection = $this->connection();
        $connection->setSuppressReasoning(false);

        self::assertSame(Reasoning::Allowed, Reasoning::preferredBy($connection));
    }

    public function testAModelThatRefusedSuppressionIsAllowedToReason(): void
    {
        $connection = $this->connection();
        $connection->recordSuppressionRefused();

        self::assertSame(Reasoning::Allowed, Reasoning::preferredBy($connection));
    }

    private function connection(): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('reasoning@example.test', new \DateTimeImmutable('2026-08-16 09:00:00')),
        );
        $connection->chooseModel('model-a', new \DateTimeImmutable('2026-08-16 09:00:00'), 32768);

        return $connection;
    }
}
