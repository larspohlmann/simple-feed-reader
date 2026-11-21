<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;
use App\Service\Curation\Recommendation\Feed\Support\RunIdText;

final readonly class DebugLogRunRequestModel
{
    public function __construct(
        public AiRunKind $kind,
        public int $id,
    ) {
    }

    /** An id without a kind names a For you run, the only kind the panel knew before #2355. */
    public static function parse(string $kind, string $run): ?self
    {
        $parsedKind = '' === $kind ? AiRunKind::ForYou : AiRunKind::tryFrom($kind);
        $id = RunIdText::parse($run);

        return null === $parsedKind || null === $id ? null : new self($parsedKind, $id);
    }

    public function isFor(DebugLogRunModel $run): bool
    {
        return $run->kind === $this->kind && $run->id === $this->id;
    }
}
