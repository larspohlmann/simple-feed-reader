<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use Doctrine\ORM\Mapping as ORM;

/** A connection's model and what the provider's catalog said about it when it was chosen; empty until then. */
#[ORM\Embeddable]
final class ChosenModel
{
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    /** Tokens, as /models reported it; null when the provider did not report one. */
    #[ORM\Column(nullable: true)]
    private ?int $modelContextWindow = null;

    #[ORM\Column(length: 16, nullable: true, enumType: RecommendationEngineKind::class)]
    private ?RecommendationEngineKind $modelKind = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ScoringProtocol::class)]
    private ?ScoringProtocol $scoringProtocol = null;

    public function choose(ModelDescriptor $model): void
    {
        $this->model = $model->id;
        $this->modelContextWindow = $model->contextWindow;
        $this->modelKind = $model->kind();
        $this->scoringProtocol = $model->scoringProtocol;
    }

    public function forget(): void
    {
        $this->model = null;
        $this->modelContextWindow = null;
        $this->modelKind = null;
        $this->scoringProtocol = null;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getModelContextWindow(): ?int
    {
        return $this->modelContextWindow;
    }

    public function getModelKind(): ?RecommendationEngineKind
    {
        return $this->modelKind;
    }

    public function getScoringProtocol(): ?ScoringProtocol
    {
        return $this->scoringProtocol;
    }
}
