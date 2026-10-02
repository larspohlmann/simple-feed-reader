<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Model;

use App\Service\Recommendation\Jev\Support\SystemOneJson;

/** One `POST {base}/systemone`: the model alias, the reader as `state`, one Noul question per candidate. */
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

    /** @return array{model: string, state: array<string, mixed>, questions: array<string, array<string, mixed>>} */
    public function payload(): array
    {
        return ['model' => $this->model, 'state' => $this->state, 'questions' => $this->questions];
    }

    public function toRequestBody(): string
    {
        return SystemOneJson::encode($this->payload());
    }
}
