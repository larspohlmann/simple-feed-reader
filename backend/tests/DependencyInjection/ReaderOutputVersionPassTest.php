<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\DependencyInjection\ReaderOutputSources;
use App\DependencyInjection\ReaderOutputVersionPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ReaderOutputVersionPassTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../..';

    public function testTheContainerCarriesTheFingerprintOfTheReaderSources(): void
    {
        $container = $this->processed();

        self::assertSame(
            ReaderOutputSources::under(self::PROJECT_DIR)->fingerprint(),
            $container->getParameter(ReaderOutputVersionPass::PARAMETER),
        );
    }

    public function testTheExtractionModulesAreAmongTheSources(): void
    {
        $directories = ReaderOutputSources::under(self::PROJECT_DIR)->moduleDirectories;

        self::assertContains(self::PROJECT_DIR . '/src/Service/Reader', $directories);
        self::assertContains(self::PROJECT_DIR . '/src/Service/Html', $directories);
        self::assertContains(self::PROJECT_DIR . '/src/Service/Fetch', $directories);
    }

    public function testTheContainerRebuildsWhenASourceChanges(): void
    {
        $resources = array_map($this->described(...), $this->processed()->getResources());

        $readerModule = realpath(self::PROJECT_DIR . '/src/Service/Reader');
        self::assertContains('directory ' . $readerModule . ' /\.php$/', $resources);
        self::assertContains('file ' . realpath(self::PROJECT_DIR . '/config/services.yaml'), $resources);
    }

    private function described(object $resource): string
    {
        return match (true) {
            $resource instanceof DirectoryResource
                => 'directory ' . $resource->getResource() . ' ' . $resource->getPattern(),
            $resource instanceof FileResource => 'file ' . $resource->getResource(),
            default => $resource::class,
        };
    }

    private function processed(): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.project_dir' => self::PROJECT_DIR]));
        (new ReaderOutputVersionPass())->process($container);

        return $container;
    }
}
