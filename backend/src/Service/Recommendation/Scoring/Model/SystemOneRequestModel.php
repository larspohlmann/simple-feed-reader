<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Support\PrettyJson;

final readonly class SystemOneRequestModel
{
    /**
     * @param array<string, mixed>                $state
     * @param array<string, array<string, mixed>> $questions keyed by QuestionId
     */
    public function __construct(
        public string $model,
        public array $state,
        public array $questions,
    ) {
    }

    public function toRequestBody(): string
    {
        return CompactJson::encode($this->payload());
    }

    public function toRenderedRequest(): string
    {
        return PrettyJson::of($this->payload());
    }

    /** @return array{model: string, state: array<string, mixed>, questions: array<string, array<string, mixed>>} */
    private function payload(): array
    {
        return ['model' => $this->model, 'state' => $this->state, 'questions' => $this->questions];
    }
}
