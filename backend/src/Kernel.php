<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // The schema stores datetimes as naive UTC, so PHP's default timezone is
        // part of the persistence contract: a worker on local time hydrates every
        // stored value wrong and writes local wall clock back. Strato's FastCGI
        // workers default to Europe/Berlin (shipping `publishedAt: …+02:00`, #153),
        // so the kernel pins UTC rather than trust any host's ini.
        // KernelTimezoneTest keeps it that way.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
