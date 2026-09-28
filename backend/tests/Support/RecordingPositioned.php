<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PositionedInterface;

final class RecordingPositioned implements PositionedInterface
{
    public ?int $position = null;

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
