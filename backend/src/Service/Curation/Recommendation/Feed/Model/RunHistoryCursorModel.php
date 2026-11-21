<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;

final readonly class RunHistoryCursorModel
{
    /** 9999-12-31 23:59:59 UTC: MySQL cannot bind a DATETIME past it. */
    private const int LAST_DATETIME_SECOND = 253402300799;
    private const int MAX_ID_DIGITS = 18;

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
        if (null === $kind || !ctype_digit($seconds) || !ctype_digit($id) || strlen($id) > self::MAX_ID_DIGITS) {
            return null;
        }

        return self::buildInRange((int) $seconds, $kind, (int) $id);
    }

    public function toCursor(): string
    {
        return sprintf('%d-%s-%d', $this->createdAt->getTimestamp(), $this->kind->value, $this->id);
    }

    private static function buildInRange(int $timestamp, AiRunKind $kind, int $id): ?self
    {
        if ($timestamp > self::LAST_DATETIME_SECOND || $id < 1) {
            return null;
        }

        return new self(\DateTimeImmutable::createFromTimestamp($timestamp), $kind, $id);
    }
}
