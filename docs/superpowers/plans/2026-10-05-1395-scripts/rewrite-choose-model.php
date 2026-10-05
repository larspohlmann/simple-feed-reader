<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-10-05-1395-scripts/rewrite-choose-model.php   (from backend/)
// AiProviderSettings::chooseModel($model, $verifiedAt, $contextWindow) becomes
// chooseModel(new ModelDescriptor($model, $contextWindow), $verifiedAt) in tests/. A literal 'jev-…' model gets
// ScoringProtocol::SystemOne, as Version20261005140100's backfill does. Prints each file and every call left over.

const CALL = '/(?<!\$this)->chooseModel\(([^,()]+), (new \\\\DateTimeImmutable\([^()]*\)|\$\w+), ([^,()]+)\)/';

function descriptorOf(string $model, string $contextWindow): string
{
    $protocol = str_starts_with($model, "'jev-") ? ', ScoringProtocol::SystemOne' : '';

    return sprintf('new ModelDescriptor(%s, %s%s)', $model, $contextWindow, $protocol);
}

function importSortKey(string $line): string
{
    return strtolower(str_replace('\\', ' ', rtrim($line, ';')));
}

function withImport(string $code, string $class): string
{
    if (str_contains($code, "\nuse {$class};\n")) {
        return $code;
    }

    return (string) preg_replace_callback(
        '/(?:^use [^;\n]+;\n)+/m',
        static function (array $block) use ($class): string {
            $lines = [...explode("\n", rtrim($block[0], "\n")), "use {$class};"];
            usort($lines, static fn (string $left, string $right): int => strcmp(importSortKey($left), importSortKey($right)));

            return implode("\n", $lines) . "\n";
        },
        $code,
        1,
    );
}

$rewritten = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('tests', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/PhpStan/')) {
        continue;
    }
    $code = (string) file_get_contents($path);
    $scoring = false;
    $changed = (string) preg_replace_callback(
        CALL,
        static function (array $call) use (&$scoring): string {
            $scoring = $scoring || str_starts_with($call[1], "'jev-");

            return sprintf('->chooseModel(%s, %s)', descriptorOf($call[1], $call[3]), $call[2]);
        },
        $code,
    );
    if ($changed === $code) {
        continue;
    }
    $changed = withImport($changed, 'App\Entity\ModelDescriptor');
    if ($scoring) {
        $changed = withImport($changed, 'App\Enum\ScoringProtocol');
    }
    file_put_contents($path, $changed);
    ++$rewritten;
    echo $path, "\n";
}
printf("Rewrote chooseModel() in %d files.\n", $rewritten);
echo "Calls left over:\n";
passthru("git grep -n -e '->chooseModel(' -- tests | grep -v -e 'new ModelDescriptor' -e '\$this->chooseModel(' -e 'configurator->chooseModel('");
