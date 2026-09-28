<?php

declare(strict_types=1);

namespace App\Service\Profiling;

use App\Service\Profiling\ProfileSampler\ExcimerSampler;
use App\Service\Profiling\ProfileSampler\NullProfileSampler;
use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;

final readonly class ProfileSamplerFactory
{
    public function create(): ProfileSamplerInterface
    {
        $excimer = new ExcimerSampler();

        return $excimer->isAvailable() ? $excimer : new NullProfileSampler();
    }
}
