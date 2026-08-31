<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * One signal that the reader pipeline probably did not clean an article well.
 * `suspect` names the stage to look at first, so the report groups candidates by
 * the code that would have to change rather than by symptom alone.
 */
final readonly class CleanupMarker
{
    public function __construct(
        public string $code,
        public int $weight,
        public string $suspect,
        public string $detail,
    ) {
    }

    /** @return array{code: string, weight: int, suspect: string, detail: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'weight' => $this->weight,
            'suspect' => $this->suspect,
            'detail' => $this->detail,
        ];
    }

    /** @param array{code: string, weight: int, suspect: string, detail: string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['code'], $row['weight'], $row['suspect'], $row['detail']);
    }
}
