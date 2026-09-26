<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Positioned;

final class RecordingPositioned implements Positioned
{
    public ?int $position = null;

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
