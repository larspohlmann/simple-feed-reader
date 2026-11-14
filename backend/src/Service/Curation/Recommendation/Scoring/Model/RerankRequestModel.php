<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

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

    /** @return array{model: string, query: string, documents: list<string>} */
    public function payload(): array
    {
        return ['model' => $this->model, 'query' => $this->query, 'documents' => array_values($this->documents)];
    }
}
