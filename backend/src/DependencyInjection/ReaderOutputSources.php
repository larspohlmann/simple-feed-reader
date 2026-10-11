<?php

declare(strict_types=1);

namespace App\DependencyInjection;

/**
 * The files whose change can change what the reader endpoint returns: the Reader module, every service module it
 * reaches through imports, the other classes those import directly, the response mapper, the wiring and the
 * installed packages. Over-inclusion only costs a cache refill; a missing source serves stale articles (#1561).
 */
final readonly class ReaderOutputSources
{
    private const string ROOT_MODULE = 'Reader';

    private const string RESPONSE_MAPPER = 'src/Http/ReaderJson.php';

    private const array FIXED_FILES = [self::RESPONSE_MAPPER, 'config/services.yaml', 'vendor/composer/installed.php'];

    /**
     * @param list<string> $moduleDirectories
     * @param list<string> $files
     * @param list<string> $moduleFiles
     */
    private function __construct(
        private string $projectDir,
        public array $moduleDirectories,
        public array $files,
        private array $moduleFiles,
    ) {
    }

    public static function under(string $projectDir): self
    {
        $closure = new ImportClosure($projectDir . '/src');
        $closure->followFile($projectDir . '/' . self::RESPONSE_MAPPER);
        $closure->followModule(self::ROOT_MODULE);

        $fixedFiles = array_map(static fn (string $path): string => $projectDir . '/' . $path, self::FIXED_FILES);

        return new self(
            $projectDir,
            $closure->moduleDirectories(),
            [...$fixedFiles, ...$closure->importedFiles()],
            $closure->moduleFiles(),
        );
    }

    public function fingerprint(): string
    {
        $hash = hash_init('xxh128');
        foreach ($this->everyFile() as $file) {
            hash_update($hash, substr($file, \strlen($this->projectDir)));
            hash_update_file($hash, $file);
        }

        return hash_final($hash);
    }

    /** @return list<string> */
    private function everyFile(): array
    {
        $files = [...$this->files, ...$this->moduleFiles];
        sort($files);

        return $files;
    }
}
