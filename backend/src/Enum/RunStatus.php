<?php

declare(strict_types=1);

namespace App\Enum;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Terminal, and reached only by the user stopping the run themselves. */
    case Cancelled = 'cancelled';

    /** Over for good. resume() keeps completedAt, so "has a completion time" is not this question. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Pending, self::Running => false,
        };
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    /** @return list<self> */
    public static function active(): array
    {
        $active = [];
        foreach (self::cases() as $status) {
            if ($status->isActive()) {
                $active[] = $status;
            }
        }

        return $active;
    }
}
