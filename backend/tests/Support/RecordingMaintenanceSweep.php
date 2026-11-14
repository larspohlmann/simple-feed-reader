<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Operations\Maintenance\MaintenanceSweep\MaintenanceSweepInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** A maintenance sweep that records the deadlines it was run with. */
#[AsTaggedItem(index: 'recording')]
final class RecordingMaintenanceSweep implements MaintenanceSweepInterface
{
    /** @var list<\DateTimeImmutable> */
    public array $deadlines = [];

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
