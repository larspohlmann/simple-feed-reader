<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;
use App\Service\Curation\Recommendation\Feed\Support\RunIdText;

final readonly class RunHistoryCursorModel
{
    /** 9999-12-31 23:59:59 UTC: MySQL cannot bind a DATETIME past it. */
    private const int LAST_DATETIME_SECOND = 253402300799;

    public function __construct(
        public \DateTimeImmutable $createdAt,
        public AiRunKind $kind,
        public int $id,
    ) {
    }

    public static function parse(string $cursor): ?self
    {
        $parts = explode('-', $cursor);
        if (3 !== \count($parts)) {
            return null;
        }

        $seconds = $parts[0];
        $kind = AiRunKind::tryFrom($parts[1]);
        $id = RunIdText::parse($parts[2]);
        if (null === $kind || null === $id || !ctype_digit($seconds) || (int) $seconds > self::LAST_DATETIME_SECOND) {
            return null;
        }

        return new self(\DateTimeImmutable::createFromTimestamp((int) $seconds), $kind, $id);
    }

    public function toCursor(): string
    {
        return sprintf('%d-%s-%d', $this->createdAt->getTimestamp(), $this->kind->value, $this->id);
    }

    /** Within one second the kind rank, then the id, orders the runs. */
    public function compareNewestFirst(self $other): int
    {
        return [$other->createdAt, $other->kind->tieRank(), $other->id]
            <=> [$this->createdAt, $this->kind->tieRank(), $this->id];
    }
}
