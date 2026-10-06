<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Support\PrettyJson;

final readonly class RerankRequestModel
{
    /** @param non-empty-array<int, string> $documents entry id => its document, in the batch's order */
    public function __construct(
        public string $model,
        public string $query,
        public array $documents,
    ) {
    }

    /** @return list<int> in the documents' order, so a result's index names its entry */
    public function entryIds(): array
    {
        return array_keys($this->documents);
    }

    public function toRequestBody(): string
    {
        return CompactJson::encode($this->payload());
    }

    public function toRenderedRequest(): string
    {
        return PrettyJson::of($this->payload());
    }

    /** @return array{model: string, query: string, documents: list<string>} */
    private function payload(): array
    {
        return ['model' => $this->model, 'query' => $this->query, 'documents' => array_values($this->documents)];
    }
}
