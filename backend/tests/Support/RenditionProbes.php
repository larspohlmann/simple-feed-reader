<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Image\GuessedSizeRungs;
use App\Service\Image\RenditionProbe;
use App\Service\Image\ResizerRungs;

final class RenditionProbes
{
    public static function over(StubGuardedImageProbe $probe): RenditionProbe
    {
        return new RenditionProbe(new ResizerRungs($probe), new GuessedSizeRungs($probe));
    }

    public static function none(): RenditionProbe
    {
        return self::over(new StubGuardedImageProbe());
    }

    private function __construct()
    {
    }
}
