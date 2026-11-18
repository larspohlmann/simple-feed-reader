<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Service\Curation\StoryAi\Model\StorySettingsModel;

/** The story settings' state. `writingConnection` is the one that writes; null means none can. */
final class StorySettingsJson
{
    /** @return array<string, mixed> */
    public static function state(StorySettingsModel $settings): array
    {
        return [
            'writingConnectionId' => $settings->storedWritingConnection?->getId(),
            'writingConnection' => null === $settings->writingConnection
                ? null
                : self::connection($settings->writingConnection),
            'writingCandidates' => array_map(self::connection(...), $settings->writingCandidates),
            'titlesEnabled' => $settings->titlesEnabled,
        ];
    }

    /** @return array{id: ?int, name: ?string, baseUrl: string, model: ?string} */
    private static function connection(AiProviderSettings $connection): array
    {
        return [
            'id' => $connection->getId(),
            'name' => $connection->getName(),
            'baseUrl' => $connection->getBaseUrl(),
            'model' => $connection->getModel(),
        ];
    }

    private function __construct()
    {
    }
}
