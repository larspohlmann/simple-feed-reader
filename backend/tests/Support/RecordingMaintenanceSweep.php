<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Maintenance\MaintenanceSweep\MaintenanceSweepInterface;

/** A maintenance sweep that records the deadlines it was run with. */
final class RecordingMaintenanceSweep implements MaintenanceSweepInterface
{
    /** @var list<\DateTimeImmutable> */
    public array $deadlines = [];

    public static function name(): string
    {
        return 'recording';
    }

    public static function priority(): int
    {
        return 0;
    }

    public static function ran(): \stdClass
    {
        return (object) ['ran' => true];
    }

    public static function idle(): \stdClass
    {
        return (object) ['ran' => false];
    }

    public function run(\DateTimeImmutable $deadline): \stdClass
    {
        $this->deadlines[] = $deadline;

        return self::ran();
    }

    public function idleReport(): \stdClass
    {
        return self::idle();
    }
}
