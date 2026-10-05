<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-10-02-1344-scripts/psr4-namespaces.php   (from backend/)
// Every PHP file in src and tests declares the namespace its path implies (PSR-4: App\ => src/, App\Tests\ => tests/).
// Prints each file that does not, then the count. PHPStan rule fixtures declare made-up namespaces on purpose.

$offPath = 0;
foreach (['src' => 'App', 'tests' => 'App\\Tests'] as $root => $prefix) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/PhpStan/data/')) {
            continue;
        }
        if (1 !== preg_match('/^namespace ([^;{]+);$/m', (string) file_get_contents($path), $match)) {
            continue;
        }
        $relativeDirectory = substr(dirname($path), strlen($root) + 1);
        $expected = '' === $relativeDirectory ? $prefix : $prefix . '\\' . str_replace('/', '\\', $relativeDirectory);
        if ($match[1] !== $expected) {
            printf("%s declares %s, expected %s\n", $path, $match[1], $expected);
            ++$offPath;
        }
    }
}
printf("%d files off their PSR-4 namespace.\n", $offPath);
