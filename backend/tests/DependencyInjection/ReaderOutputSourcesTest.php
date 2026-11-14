<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\DependencyInjection\ReaderOutputSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ReaderOutputSourcesTest extends TestCase
{
    private const array TREE = [
        'src/Service/Content/Reader/Extractor.php' =>
            "<?php\nuse App\\Service\\Foundation\\Html\\Support\\Walker;\nuse App\\Entity\\Entry;\n",
        'src/Service/Content/Reader/Model/Article.php' => "<?php\nuse App\\Entity\\Entry;\n",
        'src/Service/Content/Reader/README.md' => "Notes\n",
        'src/Service/Foundation/Html/Support/Walker.php' => "<?php\nuse App\\Service\\Foundation\\Text\\Normalizer;\n",
        'src/Service/Foundation/Text/Normalizer.php' => "<?php\n",
        'src/Service/Operations/Unrelated/Mailer.php' => "<?php\nuse App\\Service\\Content\\Reader\\Extractor;\n",
        'src/Entity/Entry.php' => "<?php\nuse App\\Entity\\Feed;\n",
        'src/Entity/Feed.php' => "<?php\n",
        'src/Http/ReaderJson.php' => "<?php\nuse App\\Service\\Outbound\\Image\\DeclaredImage;\nuse App\\Enum\\Kind;\n",
        'src/Enum/Kind.php' => "<?php\n",
        'src/Service/Outbound/Image/DeclaredImage.php' => "<?php\n",
        'config/services.yaml' => "services: ~\n",
        'vendor/composer/installed.php' => "<?php return [];\n",
    ];

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/reader-output-sources-' . bin2hex(random_bytes(6));
        foreach (self::TREE as $path => $contents) {
            (new Filesystem())->dumpFile($this->projectDir . '/' . $path, $contents);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testTheReaderModuleAndEveryModuleItReachesAreSources(): void
    {
        $sources = ReaderOutputSources::under($this->projectDir);

        self::assertSame(
            [
                'src/Service/Content/Reader',
                'src/Service/Foundation/Html',
                'src/Service/Foundation/Text',
                'src/Service/Outbound/Image',
            ],
            $this->relative($sources->moduleDirectories),
        );
    }

    public function testADirectlyImportedClassIsASourceButNotWhatItImports(): void
    {
        $sources = ReaderOutputSources::under($this->projectDir);

        self::assertSame(
            [
                'src/Http/ReaderJson.php',
                'config/services.yaml',
                'vendor/composer/installed.php',
                'src/Enum/Kind.php',
                'src/Entity/Entry.php',
            ],
            $this->relative($sources->files),
        );
    }

    public function testAnUnchangedTreeKeepsItsFingerprint(): void
    {
        self::assertSame(
            ReaderOutputSources::under($this->projectDir)->fingerprint(),
            ReaderOutputSources::under($this->projectDir)->fingerprint(),
        );
    }

    #[DataProvider('sources')]
    public function testChangingASourceMovesTheFingerprint(string $path): void
    {
        $before = ReaderOutputSources::under($this->projectDir)->fingerprint();

        (new Filesystem())->appendToFile($this->projectDir . '/' . $path, "// changed\n");

        self::assertNotSame($before, ReaderOutputSources::under($this->projectDir)->fingerprint());
    }

    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        yield 'the reader module' => ['src/Service/Content/Reader/Model/Article.php'];
        yield 'a module the reader imports' => ['src/Service/Foundation/Html/Support/Walker.php'];
        yield 'a module reached through another' => ['src/Service/Foundation/Text/Normalizer.php'];
        yield 'a module only the response mapper imports' => ['src/Service/Outbound/Image/DeclaredImage.php'];
        yield 'a class the reader imports' => ['src/Entity/Entry.php'];
        yield 'a class the response mapper imports' => ['src/Enum/Kind.php'];
        yield 'the response mapper' => ['src/Http/ReaderJson.php'];
        yield 'the wiring' => ['config/services.yaml'];
        yield 'the installed packages' => ['vendor/composer/installed.php'];
    }

    public function testTheSameTreeElsewhereHasTheSameFingerprint(): void
    {
        $copy = $this->projectDir . '-copy';
        (new Filesystem())->mirror($this->projectDir, $copy);

        try {
            self::assertSame(
                ReaderOutputSources::under($this->projectDir)->fingerprint(),
                ReaderOutputSources::under($copy)->fingerprint(),
            );
        } finally {
            (new Filesystem())->remove($copy);
        }
    }

    public function testRenamingASourceMovesTheFingerprint(): void
    {
        $before = ReaderOutputSources::under($this->projectDir)->fingerprint();

        (new Filesystem())->rename(
            $this->projectDir . '/src/Service/Foundation/Text/Normalizer.php',
            $this->projectDir . '/src/Service/Foundation/Text/Collapser.php',
        );

        self::assertNotSame($before, ReaderOutputSources::under($this->projectDir)->fingerprint());
    }

    public function testAddingAFileToAReachedModuleMovesTheFingerprint(): void
    {
        $before = ReaderOutputSources::under($this->projectDir)->fingerprint();

        (new Filesystem())->dumpFile($this->projectDir . '/src/Service/Foundation/Html/Support/Cleaner.php', "<?php\n");

        self::assertNotSame($before, ReaderOutputSources::under($this->projectDir)->fingerprint());
    }

    #[DataProvider('nonSources')]
    public function testChangingANonSourceKeepsTheFingerprint(string $path): void
    {
        $before = ReaderOutputSources::under($this->projectDir)->fingerprint();

        (new Filesystem())->appendToFile($this->projectDir . '/' . $path, "// changed\n");

        self::assertSame($before, ReaderOutputSources::under($this->projectDir)->fingerprint());
    }

    /** @return iterable<string, array{string}> */
    public static function nonSources(): iterable
    {
        yield 'a module that imports the reader' => ['src/Service/Operations/Unrelated/Mailer.php'];
        yield 'a class only an imported class imports' => ['src/Entity/Feed.php'];
        yield 'a file in a reached module that is not PHP' => ['src/Service/Content/Reader/README.md'];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function relative(array $paths): array
    {
        return array_map(fn (string $path): string => substr($path, \strlen($this->projectDir) + 1), $paths);
    }
}
