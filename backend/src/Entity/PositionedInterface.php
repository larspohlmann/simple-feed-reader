<?php

declare(strict_types=1);

namespace App\Entity;

interface PositionedInterface
{
    public function setPosition(int $position): void;
}
