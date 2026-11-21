<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;

final readonly class RunHistoryCursorModel
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public AiRunKind $kind,
        public int $id,
    ) {
    }

    public static function parse(string $cursor): ?self
    {
        $firstDash = strpos($cursor, '-');
        $lastDash = strrpos($cursor, '-');
        if (false === $firstDash || $firstDash === $lastDash) {
            return null;
        }

        $seconds = substr($cursor, 0, $firstDash);
        $kind = AiRunKind::tryFrom(substr($cursor, $firstDash + 1, $lastDash - $firstDash - 1));
        $id = substr($cursor, $lastDash + 1);
        if (null === $kind || !ctype_digit($seconds) || !ctype_digit($id) || (int) $id < 1) {
            return null;
        }

        return new self(new \DateTimeImmutable('@' . $seconds), $kind, (int) $id);
    }

    public function toCursor(): string
    {
        return sprintf('%d-%s-%d', $this->createdAt->getTimestamp(), $this->kind->value, $this->id);
    }
}
