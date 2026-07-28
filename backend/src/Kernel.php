<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // The schema stores datetimes as naive UTC, so PHP's default timezone
        // is part of the persistence contract, not a host preference: a worker
        // defaulting to local time hydrates every stored value into the wrong
        // instant and writes local wall clock back. Strato's externally-routed
        // FastCGI workers default to Europe/Berlin (observed shipping
        // `publishedAt: …+02:00`, #153), so the kernel pins UTC itself rather
        // than trusting any host's ini. KernelTimezoneTest keeps it that way.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
