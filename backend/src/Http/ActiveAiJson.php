<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Service\Ai\Support\AiReadiness;

/** The `ai` block of /api/me: the active connection's readiness, model and engine capabilities. */
final readonly class ActiveAiJson
{
    public function __construct(private RecommendationCapabilitiesJson $capabilities)
    {
    }

    /** @return array{ready: bool, model: ?string, capabilities: array{reasons: bool, tuningFields: list<string>}|null} */
    public function of(User $user): array
    {
        $connection = $user->getActiveAiProviderSettings();

        return [
            'ready' => AiReadiness::of($connection),
            'model' => $connection?->getModel(),
            'capabilities' => null === $connection ? null : $this->capabilities->of($connection),
        ];
    }
}
