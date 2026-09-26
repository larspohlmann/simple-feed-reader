<?php

declare(strict_types=1);

namespace App\Entity;

interface Positioned
{
    public function setPosition(int $position): void;
}
