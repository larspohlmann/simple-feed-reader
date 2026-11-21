<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;

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
        $firstDash = strpos($cursor, '-');
        $lastDash = strrpos($cursor, '-');
        if (false === $firstDash || $firstDash === $lastDash) {
            return null;
        }

        $seconds = substr($cursor, 0, $firstDash);
        $kind = AiRunKind::tryFrom(substr($cursor, $firstDash + 1, $lastDash - $firstDash - 1));
        $id = substr($cursor, $lastDash + 1);
        if (null === $kind || !self::isNumber($seconds, 12) || !self::isNumber($id, 18) || (int) $id < 1) {
            return null;
        }
        if ((int) $seconds > self::LAST_DATETIME_SECOND) {
            return null;
        }

        return new self(new \DateTimeImmutable('@' . $seconds), $kind, (int) $id);
    }

    private static function isNumber(string $digits, int $maxLength): bool
    {
        return ctype_digit($digits) && strlen($digits) <= $maxLength;
    }

    public function toCursor(): string
    {
        return sprintf('%d-%s-%d', $this->createdAt->getTimestamp(), $this->kind->value, $this->id);
    }
}
