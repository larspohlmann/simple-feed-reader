<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-10-02-1345-scripts/snapshot-calls.php   (from backend/)
// #1345 A3: every test call of RecommendationRun::snapshot() gains the LLM kind as its first argument, and its file
// imports App\Enum\RecommendationEngineKind (in alphabetical place). Prints each changed file, then the call count.

const KIND_IMPORT = 'use App\\Enum\\RecommendationEngineKind;';

function withImport(string $code): string
{
    preg_match_all('/^use [^;]+;$/m', $code, $uses, \PREG_OFFSET_CAPTURE);
    foreach ($uses[0] as [$line, $offset]) {
        if (strcmp($line, KIND_IMPORT) > 0) {
            return substr_replace($code, KIND_IMPORT . "\n", $offset, 0);
        }
    }
    $last = end($uses[0]);
    if (false === $last) {
        throw new RuntimeException('A test file without imports calls snapshot(); add the import by hand.');
    }

    return substr_replace($code, "\n" . KIND_IMPORT, $last[1] + strlen($last[0]), 0);
}

$rewritten = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('tests', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/PhpStan/')) {
        continue;
    }
    $code = (string) file_get_contents($path);
    // Not `$this->snapshot(`: SnapshotPhaseTest names its own SnapshotPhase builder snapshot().
    $code = (string) preg_replace(
        '/(?<!\$this)->snapshot\(/',
        '->snapshot(RecommendationEngineKind::Llm, ',
        $code,
        -1,
        $count,
    );
    if (0 === $count) {
        continue;
    }
    $rewritten += $count;
    file_put_contents($path, str_contains($code, KIND_IMPORT) ? $code : withImport($code));
    echo $path, "\n";
}
printf("%d calls rewritten.\n", $rewritten);
