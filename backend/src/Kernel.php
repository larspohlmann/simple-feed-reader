<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\DisableHttpServerPushPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DisableHttpServerPushPass());
    }

    public function boot(): void
    {
        // Datetimes are stored as naive UTC, so a worker on local time reads and writes every one wrong; Strato's
        // FastCGI workers default to Europe/Berlin (#153). KernelTimezoneTest pins this.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
