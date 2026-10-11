<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Derives the reader-output version from its sources, so no change to the extraction can ship without it moving. */
final readonly class ReaderOutputVersionPass implements CompilerPassInterface
{
    public const string PARAMETER = 'app.reader_output_version';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $sources = ReaderOutputSources::under($projectDir);

        $container->setParameter(self::PARAMETER, $sources->fingerprint());

        foreach ($sources->moduleDirectories as $directory) {
            $container->addResource(new DirectoryResource($directory, '/\.php$/'));
        }
        foreach ($sources->files as $file) {
            $container->addResource(new FileResource($file));
        }
    }
}
