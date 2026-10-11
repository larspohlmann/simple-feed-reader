<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Finder\Finder;

/**
 * Walks `use App\…` imports: an imported service module is followed whole and in turn, any other imported class is
 * recorded without following it further, because the entities alone reach the whole tree.
 */
final class ImportClosure
{
    private const string IMPORT = '/^use App\\\\([\w\\\\]+)(?: as \w+)?;/m';

    private const string SERVICE_MODULE = '/^Service\\\\(\w+)\\\\/';

    /** @var array<string, string> */
    private array $modules = [];

    /** @var list<string> */
    private array $moduleFiles = [];

    /** @var array<string, string> */
    private array $importedFiles = [];

    public function __construct(private readonly string $sourceDirectory)
    {
    }

    public function followModule(string $module): void
    {
        if (isset($this->modules[$module])) {
            return;
        }

        $this->modules[$module] = $this->sourceDirectory . '/Service/' . $module;
        foreach ((new Finder())->files()->in($this->modules[$module])->name('*.php') as $file) {
            $this->moduleFiles[] = $file->getPathname();
            $this->followFile($file->getPathname());
        }
    }

    public function followFile(string $file): void
    {
        preg_match_all(self::IMPORT, (string) file_get_contents($file), $imports);
        foreach ($imports[1] as $class) {
            $this->followClass($class);
        }
    }

    /** @return list<string> */
    public function moduleDirectories(): array
    {
        $directories = $this->modules;
        sort($directories);

        return $directories;
    }

    /** @return list<string> */
    public function moduleFiles(): array
    {
        return $this->moduleFiles;
    }

    /** @return list<string> */
    public function importedFiles(): array
    {
        return array_values($this->importedFiles);
    }

    private function followClass(string $class): void
    {
        if (preg_match(self::SERVICE_MODULE, $class, $module) === 1) {
            $this->followModule($module[1]);

            return;
        }

        $file = $this->sourceDirectory . '/' . str_replace('\\', '/', $class) . '.php';
        $this->importedFiles[$file] = $file;
    }
}
