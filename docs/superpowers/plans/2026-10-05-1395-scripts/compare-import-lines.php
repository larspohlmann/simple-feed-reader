<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-import-lines.php <map>.php [<base ref>]   (from backend/)
// The two lines compare-moves.php skips: every PHP file's namespace line and its class imports, against the base ref
// with the map applied. A move once corrupted namespace lines behind a clean comparison (#1202).

require __DIR__ . '/class-names.php';

/** @return array{namespace: string, imports: list<string>} */
function headerOf(string $code): array
{
    $namespace = 1 === preg_match('/^namespace ([^;{]+);$/m', $code, $match) ? $match[1] : '';
    preg_match_all('/^use (?!function |const )([\w\\\\]+)(?: as \w+)?;$/m', $code, $imports);
    $sorted = $imports[1];
    sort($sorted);

    return ['namespace' => $namespace, 'imports' => $sorted];
}

/** @var array<string, string> $moves */
$moves = require $argv[1];
$base = $argv[2] ?? 'HEAD';
$oldPathOf = [];
foreach ($moves as $old => $new) {
    $oldPathOf[pathOf($new)] = pathOf($old);
}

$compared = 0;
$wrongNamespaces = 0;
$changedImports = 0;
foreach (['src', 'tests'] as $root) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/PhpStan/data/')) {
            continue;
        }
        $oldPath = $oldPathOf[$path] ?? $path;
        $before = shell_exec(sprintf('git show %s 2>/dev/null', escapeshellarg($base . ':backend/' . $oldPath)));
        if (!is_string($before) || '' === $before) {
            continue;
        }
        ++$compared;
        $old = headerOf($before);
        $new = headerOf((string) file_get_contents($path));
        $oldClass = $old['namespace'] . '\\' . basename($oldPath, '.php');
        $expectedNamespace = namespaceOf($moves[$oldClass] ?? $oldClass);
        if ($new['namespace'] !== $expectedNamespace) {
            ++$wrongNamespaces;
            printf("%s declares namespace %s, expected %s\n", $path, $new['namespace'], $expectedNamespace);
        }
        $expectedImports = array_map(static fn (string $class): string => $moves[$class] ?? $class, $old['imports']);
        sort($expectedImports);
        if ($new['imports'] !== $expectedImports) {
            ++$changedImports;
            printf(
                "%s imports differ: missing [%s], unexpected [%s]\n",
                $path,
                implode(', ', array_diff($expectedImports, $new['imports'])),
                implode(', ', array_diff($new['imports'], $expectedImports)),
            );
        }
    }
}
printf("%d files compared.\n", $compared);
printf("%d files declare an unexpected namespace.\n", $wrongNamespaces);
printf("%d files import other classes than the map says.\n", $changedImports);
