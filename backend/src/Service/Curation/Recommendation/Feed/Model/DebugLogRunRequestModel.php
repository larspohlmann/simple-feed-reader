<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;

final readonly class DebugLogRunRequestModel
{
    private const int MAX_ID_DIGITS = 18;

    public function __construct(
        public AiRunKind $kind,
        public int $id,
    ) {
    }

    /** An id without a kind names a For you run, the only kind the panel knew before #2355. */
    public static function parse(string $kind, string $run): ?self
    {
        $parsedKind = '' === $kind ? AiRunKind::ForYou : AiRunKind::tryFrom($kind);
        if (null === $parsedKind || !ctype_digit($run) || strlen($run) > self::MAX_ID_DIGITS || (int) $run < 1) {
            return null;
        }

        return new self($parsedKind, (int) $run);
    }

    public function isFor(DebugLogRunModel $run): bool
    {
        return $run->kind === $this->kind && $run->id === $this->id;
    }
}
