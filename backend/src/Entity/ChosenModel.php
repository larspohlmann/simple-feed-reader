<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScoringProtocol;
use App\Enum\TokenLimitParameter;
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

    #[ORM\Column(length: 16, nullable: true, enumType: ScoringProtocol::class)]
    private ?ScoringProtocol $scoringProtocol = null;

    /** Tokens; from /models where reported, else from the provider refusing a larger request. */
    #[ORM\Column(nullable: true)]
    private ?int $modelMaxOutputTokens = null;

    #[ORM\Column(length: 32, enumType: TokenLimitParameter::class, options: ['default' => 'max_tokens'])]
    private TokenLimitParameter $tokenLimitParameter = TokenLimitParameter::MaxTokens;

    public function choose(ModelDescriptor $model): void
    {
        if ($model->id !== $this->model) {
            $this->forget();
        }
        $this->model = $model->id;
        $this->modelContextWindow = $model->contextWindow;
        $this->scoringProtocol = $model->scoringProtocol;
        $this->modelMaxOutputTokens = self::lowerCap($this->modelMaxOutputTokens, $model->maxOutputTokens);
    }

    public function forget(): void
    {
        $this->model = null;
        $this->modelContextWindow = null;
        $this->scoringProtocol = null;
        $this->modelMaxOutputTokens = null;
        $this->tokenLimitParameter = TokenLimitParameter::MaxTokens;
    }

    public function capOutput(int $tokens): void
    {
        $this->modelMaxOutputTokens = $tokens;
    }

    public function refuseMaxTokens(): void
    {
        $this->tokenLimitParameter = TokenLimitParameter::MaxCompletionTokens;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getModelContextWindow(): ?int
    {
        return $this->modelContextWindow;
    }

    public function getScoringProtocol(): ?ScoringProtocol
    {
        return $this->scoringProtocol;
    }

    public function getModelMaxOutputTokens(): ?int
    {
        return $this->modelMaxOutputTokens;
    }

    public function getTokenLimitParameter(): TokenLimitParameter
    {
        return $this->tokenLimitParameter;
    }

    private static function lowerCap(?int $learned, ?int $reported): ?int
    {
        if (null === $reported) {
            return $learned;
        }
        if (null === $learned) {
            return $reported;
        }

        return min($learned, $reported);
    }
}
