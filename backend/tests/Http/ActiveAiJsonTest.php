<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Http\ActiveAiJson;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationCapabilitiesJsons;
use PHPUnit\Framework\TestCase;

/** The `ai` block reads an association, not a column: it must report the ACTIVE configuration, not just any. */
final class ActiveAiJsonTest extends TestCase
{
    public function testAnAccountWithNoActiveConfigurationIsNotReadyAndHasNoCapabilities(): void
    {
        self::assertSame(
            ['ready' => false, 'model' => null, 'capabilities' => null],
            $this->json()->of($this->user()),
        );
    }

    public function testTheActiveConfigurationReportsItsModelAndItsKindsCapabilities(): void
    {
        $user = $this->user();
        $this->activate($user, 'Work OpenAI', 'gpt-4o-mini');

        self::assertSame(
            ['ready' => true, 'model' => 'gpt-4o-mini', 'capabilities' => RecommendationCapabilitiesJsons::LLM],
            $this->json()->of($user),
        );
    }

    /** Not ready, yet an engine: readiness is the model, capabilities are the connection's. */
    public function testAnActiveConfigurationWithoutAModelIsNotReadyButHasCapabilities(): void
    {
        $user = $this->user();
        $user->setActiveAiProviderSettings(AiProviderSettingsFactory::build($user, 'Fresh'));

        self::assertSame(
            ['ready' => false, 'model' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
            $this->json()->of($user),
        );
    }

    /** A verified configuration that never became active must not change the reported state. */
    public function testASecondNonActiveConfigurationDoesNotChangeTheReportedState(): void
    {
        $user = $this->user();
        $this->activate($user, 'Work OpenAI', 'gpt-4o-mini');
        $other = AiProviderSettingsFactory::build($user, 'Personal OpenRouter');
        $other->chooseModel(
            new ModelDescriptor('claude-3-haiku', null),
            new \DateTimeImmutable('2026-08-09T09:06:00Z'),
        );

        self::assertSame(
            ['ready' => true, 'model' => 'gpt-4o-mini', 'capabilities' => RecommendationCapabilitiesJsons::LLM],
            $this->json()->of($user),
        );
    }

    private function activate(User $user, string $name, string $model): void
    {
        $active = AiProviderSettingsFactory::build($user, $name);
        $active->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-08-09T09:05:00Z'));
        $user->setActiveAiProviderSettings($active);
    }

    private function json(): ActiveAiJson
    {
        return new ActiveAiJson(RecommendationCapabilitiesJsons::ofTheKind());
    }

    private function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-08-09 09:00:00'));
    }
}
