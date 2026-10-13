<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model\RequestSlot;

/** One cap a ticket holds while in flight. Slots of two classes never share a cap, whatever their keys spell. */
interface RequestSlotModelInterface
{
    public function keyFor(string $url): string;

    public function capacity(): int;
}
