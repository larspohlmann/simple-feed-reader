<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Support\CompactJson;

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

    /** Pretty-printed for the human the debug view exists for: the body as sent, minus transport framing. */
    public function toRenderedRequest(): string
    {
        return json_encode(
            $this->payload(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /** @return array{model: string, state: array<string, mixed>, questions: array<string, array<string, mixed>>} */
    private function payload(): array
    {
        return ['model' => $this->model, 'state' => $this->state, 'questions' => $this->questions];
    }
}
