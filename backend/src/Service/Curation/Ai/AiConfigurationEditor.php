<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The account's local edits to a saved configuration: its name and preferences. None calls the provider, which
 * keeps AiProviderConfigurator's rule that every write it makes is verified live.
 */
final readonly class AiConfigurationEditor
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function rename(AiProviderSettings $settings, ?string $name): void
    {
        $settings->rename($name);
        $this->entityManager->flush();
    }

    public function setSuppressReasoning(AiProviderSettings $settings, bool $suppressReasoning): void
    {
        $settings->setSuppressReasoning($suppressReasoning);
        $this->entityManager->flush();
    }

    public function setBatchConcurrency(AiProviderSettings $settings, int $batchConcurrency): void
    {
        $settings->setBatchConcurrency($batchConcurrency);
        $this->entityManager->flush();
    }

    public function setSlowModel(AiProviderSettings $settings, bool $slowModel): void
    {
        $settings->setSlowModel($slowModel);
        $this->entityManager->flush();
    }

    public function setMaxBatchSize(AiProviderSettings $settings, ?int $maxBatchSize): void
    {
        $settings->setMaxBatchSize($maxBatchSize);
        $this->entityManager->flush();
    }
}
