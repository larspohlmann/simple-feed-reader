<?php

declare(strict_types=1);

namespace App\DependencyInjection;

/**
 * Walks `use App\…` imports: an imported service module is followed whole and in turn, any other imported class is
 * recorded without following it further, because the entities alone reach the whole tree.
 */
final class ImportClosure
{
    private const string IMPORT = '/^use App\\\\([\w\\\\]+)(?: as \w+)?;/m';

    /** @var array<string, string> */
    private array $modules = [];

    /** @var array<string, true> */
    private array $importedFiles = [];

    public function __construct(private readonly string $sourceDirectory)
    {
    }

    /** @return list<string> */
    public static function phpFilesIn(string $directory): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function followModule(string $module): void
    {
        if (isset($this->modules[$module])) {
            return;
        }

        $this->modules[$module] = $this->sourceDirectory . '/Service/' . $module;
        foreach (self::phpFilesIn($this->modules[$module]) as $file) {
            $this->followFile($file);
        }
    }

    public function followFile(string $file): void
    {
        preg_match_all(self::IMPORT, (string) file_get_contents($file), $matches);
        foreach ($matches[1] as $class) {
            $this->followClass(explode('\\', $class));
        }
    }

    /** @return list<string> */
    public function moduleDirectories(): array
    {
        $directories = array_values($this->modules);
        sort($directories);

        return $directories;
    }

    /** @return list<string> */
    public function importedFiles(): array
    {
        $files = array_keys($this->importedFiles);
        sort($files);

        return $files;
    }

    /** @param list<string> $segments */
    private function followClass(array $segments): void
    {
        if ($segments[0] === 'Service' && \count($segments) > 2) {
            $this->followModule($segments[1]);

            return;
        }

        $this->importedFiles[$this->sourceDirectory . '/' . implode('/', $segments) . '.php'] = true;
    }
}
