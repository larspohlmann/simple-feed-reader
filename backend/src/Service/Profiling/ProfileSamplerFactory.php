<?php

declare(strict_types=1);

namespace App\Service\Profiling;

final readonly class ProfileSamplerFactory
{
    public function create(): ProfileSampler
    {
        $excimer = new ExcimerSampler();

        return $excimer->isAvailable() ? $excimer : new NullProfileSampler();
    }
}
