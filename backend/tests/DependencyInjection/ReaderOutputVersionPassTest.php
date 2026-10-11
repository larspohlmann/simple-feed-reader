<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\DependencyInjection\ReaderOutputSources;
use App\DependencyInjection\ReaderOutputVersionPass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReaderOutputVersionPassTest extends KernelTestCase
{
    public function testTheContainerCarriesTheFingerprintOfTheReaderSources(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        self::assertSame(
            ReaderOutputSources::under($projectDir)->fingerprint(),
            self::getContainer()->getParameter(ReaderOutputVersionPass::PARAMETER),
        );
    }

    public function testTheExtractionModulesAreAmongTheSources(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        $directories = ReaderOutputSources::under($projectDir)->moduleDirectories;

        self::assertContains($projectDir . '/src/Service/Reader', $directories);
        self::assertContains($projectDir . '/src/Service/Html', $directories);
        self::assertContains($projectDir . '/src/Service/Fetch', $directories);
    }
}
