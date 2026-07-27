<?php

declare(strict_types=1);

namespace App\Tests\Service\Version;

use App\Service\Version\Exception\MalformedVersionFileException;
use App\Service\Version\FileReleaseVersionReader;
use PHPUnit\Framework\TestCase;

final class FileReleaseVersionReaderTest extends TestCase
{
    private string $versionFilePath;

    protected function setUp(): void
    {
        $this->versionFilePath = sys_get_temp_dir() . '/version-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->versionFilePath)) {
            unlink($this->versionFilePath);
        }
    }

    private function reader(): FileReleaseVersionReader
    {
        return new FileReleaseVersionReader($this->versionFilePath);
    }

    private function writeVersionFile(string $contents): void
    {
        file_put_contents($this->versionFilePath, $contents);
    }

    public function testReadsADeployedVersionFile(): void
    {
        $this->writeVersionFile((string) json_encode([
            'version' => 'v0.5.0-dev.3',
            'commit' => 'a1b2c3d',
            'builtAt' => '2026-07-27T10:04:11Z',
        ]));

        $release = $this->reader()->read();

        self::assertSame('v0.5.0-dev.3', $release->version);
        self::assertSame('a1b2c3d', $release->commit);
        self::assertSame('2026-07-27T10:04:11Z', $release->builtAt);
    }

    /**
     * Every local checkout and every Docker run is in this state. It is the
     * normal case, so it reports a development build rather than failing.
     */
    public function testAMissingFileReportsADevelopmentBuild(): void
    {
        $release = $this->reader()->read();

        self::assertSame('dev', $release->version);
        self::assertSame('local', $release->commit);
        self::assertSame('', $release->builtAt);
    }

    public function testRejectsAFileThatIsNotJson(): void
    {
        $this->writeVersionFile('v0.5.0-dev.3');

        $this->expectException(MalformedVersionFileException::class);
        $this->reader()->read();
    }

    public function testRejectsJsonThatIsNotAnObject(): void
    {
        $this->writeVersionFile('"v0.5.0-dev.3"');

        $this->expectException(MalformedVersionFileException::class);
        $this->reader()->read();
    }

    public function testRejectsAFileMissingAField(): void
    {
        $this->writeVersionFile((string) json_encode([
            'version' => 'v0.5.0-dev.3',
            'commit' => 'a1b2c3d',
        ]));

        $this->expectException(MalformedVersionFileException::class);
        $this->reader()->read();
    }

    public function testRejectsAFieldThatIsNotAString(): void
    {
        $this->writeVersionFile((string) json_encode([
            'version' => 'v0.5.0-dev.3',
            'commit' => 'a1b2c3d',
            'builtAt' => 1753610651,
        ]));

        $this->expectException(MalformedVersionFileException::class);
        $this->reader()->read();
    }
}
