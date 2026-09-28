<?php

declare(strict_types=1);

// php docs/superpowers/plans/2026-09-28-1202-scripts/tests/run.php
// Runs move-classes.php on planted maps in throwaway git repositories and prints one line per check.

const MOVE_SCRIPT = __DIR__ . '/../move-classes.php';

/** @param array<string, string> $files path below the repository root => contents */
function plantRepository(array $files): string
{
    $root = sys_get_temp_dir() . '/move-classes-' . bin2hex(random_bytes(6));
    $files['docs/architecture.md'] ??= "# Architecture\n";
    foreach ($files as $path => $contents) {
        @mkdir(dirname("{$root}/{$path}"), 0o775, true);
        file_put_contents("{$root}/{$path}", $contents);
    }
    exec(sprintf('git -C %s init -q && git -C %1$s add -A', escapeshellarg($root)));
    register_shutdown_function(static fn () => exec(sprintf('rm -rf %s %s', escapeshellarg($root), escapeshellarg("{$root}-map.php"))));

    return $root;
}

function phpClass(string $namespace, string $name, string $imports = '', string $body = ''): string
{
    $useLines = '' === $imports ? '' : "\n{$imports}";

    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n{$useLines}\nfinal class {$name}\n{\n{$body}}\n";
}

/** @return array<string, string> path => contents, for every file under the root outside .git */
function snapshot(string $root): array
{
    $contents = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        if (!str_starts_with($path, '.git/')) {
            $contents[$path] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($contents);

    return $contents;
}

/**
 * @param array<string, string> $moves
 *
 * @return array{status: int, output: string}
 */
function runMove(string $root, array $moves): array
{
    $map = "{$root}-map.php";
    file_put_contents($map, '<?php return ' . var_export($moves, true) . ";\n");
    exec(
        sprintf('cd %s && php %s %s 2>&1', escapeshellarg("{$root}/backend"), escapeshellarg(MOVE_SCRIPT), escapeshellarg($map)),
        $output,
        $status,
    );

    return ['status' => $status, 'output' => implode("\n", $output)];
}

function importLines(string $code): string
{
    preg_match_all('/^use [^;]+;$/m', $code, $lines);

    return implode("\n", $lines[0]);
}

$failures = 0;

function check(string $name, bool $passed, string $detail): void
{
    global $failures;
    if ($passed) {
        echo "PASS {$name}\n";

        return;
    }
    ++$failures;
    echo "FAIL {$name}\n{$detail}\n";
}

/** @param array<string, string> $moves */
function checkRefused(string $name, array $files, array $moves, string $expectedLine): void
{
    $root = plantRepository($files);
    $before = snapshot($root);
    $result = runMove($root, $moves);
    $changed = array_keys(array_diff_assoc(snapshot($root), $before) + array_diff_key($before, snapshot($root)));
    check(
        $name,
        0 !== $result['status'] && str_contains($result['output'], $expectedLine) && [] === $changed,
        "  exit {$result['status']}, changed: " . implode(', ', $changed) . "\n  expected: {$expectedLine}\n"
            . "  output: {$result['output']}",
    );
}

$builder = phpClass('App\Service\Mail', 'DigestMailBuilder');
$otherFactory = phpClass('App\Service\Other', 'DigestMailFactory');
$builderMove = ['App\Service\Mail\DigestMailBuilder' => 'App\Service\Mail\Factory\DigestMailFactory'];

checkRefused(
    '(a) a new short name that an import already gives another class',
    [
        'backend/src/Service/Mail/DigestMailBuilder.php' => $builder,
        'backend/src/Service/Other/DigestMailFactory.php' => $otherFactory,
        'backend/src/Service/Consumer.php' => phpClass(
            'App\Service',
            'Consumer',
            "use App\Service\Mail\DigestMailBuilder;\nuse App\Service\Other\DigestMailFactory;\n",
            "    public function __construct(DigestMailBuilder \$builder, DigestMailFactory \$factory)\n    {\n    }\n",
        ),
    ],
    $builderMove,
    'Failed: src/Service/Consumer.php: DigestMailFactory would name App\Service\Mail\Factory\DigestMailFactory, '
        . 'but an import names App\Service\Other\DigestMailFactory',
);

checkRefused(
    '(a) a new short name that the file declares',
    [
        'backend/src/Service/Mail/DigestMailBuilder.php' => $builder,
        'backend/src/Service/DigestMailFactory.php' => phpClass(
            'App\Service',
            'DigestMailFactory',
            "use App\Service\Mail\DigestMailBuilder;\n",
            "    public function __construct(DigestMailBuilder \$builder)\n    {\n    }\n",
        ),
    ],
    $builderMove,
    'Failed: src/Service/DigestMailFactory.php: DigestMailFactory would name App\Service\Mail\Factory\DigestMailFactory, '
        . 'but the file declares App\Service\DigestMailFactory',
);

checkRefused(
    '(a) a new short name that the namespace already holds',
    [
        'backend/src/Service/Mail/DigestMailBuilder.php' => $builder,
        'backend/src/Service/DigestMailFactory.php' => phpClass('App\Service', 'DigestMailFactory'),
        'backend/src/Service/Consumer.php' => phpClass(
            'App\Service',
            'Consumer',
            "use App\Service\Mail\DigestMailBuilder;\n",
            "    public function __construct(DigestMailBuilder \$builder)\n    {\n    }\n",
        ),
    ],
    $builderMove,
    'Failed: src/Service/Consumer.php: DigestMailFactory would name App\Service\Mail\Factory\DigestMailFactory, '
        . 'but its namespace holds App\Service\DigestMailFactory',
);

checkRefused(
    '(b) two moved classes under one new short name in one file',
    [
        'backend/src/Service/Mail/DigestMailBuilder.php' => $builder,
        'backend/src/Service/Page/DigestPageMaker.php' => phpClass('App\Service\Page', 'DigestPageMaker'),
        'backend/src/Service/Consumer.php' => phpClass(
            'App\Service',
            'Consumer',
            "use App\Service\Mail\DigestMailBuilder;\nuse App\Service\Page\DigestPageMaker;\n",
            "    public function __construct(DigestMailBuilder \$mail, DigestPageMaker \$page)\n    {\n    }\n",
        ),
    ],
    [
        'App\Service\Mail\DigestMailBuilder' => 'App\Service\Mail\Factory\DigestFactory',
        'App\Service\Page\DigestPageMaker' => 'App\Service\Page\Factory\DigestFactory',
    ],
    'Failed: src/Service/Consumer.php: DigestFactory would name App\Service\Mail\Factory\DigestFactory and '
        . 'App\Service\Page\Factory\DigestFactory',
);

checkRefused(
    '(b) two moved classes landing on one path',
    [
        'backend/src/Service/Mail/DigestMailBuilder.php' => $builder,
        'backend/src/Service/Mail/DigestMailMaker.php' => phpClass('App\Service\Mail', 'DigestMailMaker'),
    ],
    [
        'App\Service\Mail\DigestMailBuilder' => 'App\Service\Mail\Factory\DigestMailFactory',
        'App\Service\Mail\DigestMailMaker' => 'App\Service\Mail\Factory\DigestMailFactory',
    ],
    'Failed: src/Service/Mail/Factory/DigestMailFactory.php: App\Service\Mail\DigestMailBuilder and '
        . 'App\Service\Mail\DigestMailMaker would both land there',
);

checkRefused(
    '(b) a moved class landing on an existing class',
    [
        'backend/src/Service/Mail/DigestMailBuilder.php' => $builder,
        'backend/src/Service/Mail/Factory/DigestMailFactory.php' => phpClass('App\Service\Mail\Factory', 'DigestMailFactory'),
    ],
    $builderMove,
    'Failed: src/Service/Mail/Factory/DigestMailFactory.php: App\Service\Mail\DigestMailBuilder would land on an existing file',
);

$unsortedImports ="use App\Zed\Last;\nuse App\Alpha\First;\n";
$root = plantRepository([
    'backend/src/Service/Mail/DigestMailBuilder.php' => $builder,
    'backend/src/Beta/Thing.php' => phpClass('App\Beta', 'Thing'),
    'backend/src/Service/Consumer.php' => phpClass(
        'App\Service',
        'Consumer',
        "use App\Beta\Thing;\nuse App\Service\Mail\DigestMailBuilder;\nuse App\Zulu\Thing\Part;\nuse Psr\Log\LoggerInterface;\n",
        "    public function __construct(DigestMailBuilder \$builder, Thing \$thing, Part \$part, LoggerInterface \$logger)\n"
            . "    {\n    }\n",
    ),
    'backend/src/Service/Untouched.php' => phpClass('App\Service', 'Untouched', $unsortedImports),
    'backend/src/Service/Documented.php' => phpClass(
        'App\Service',
        'Documented',
        $unsortedImports,
        "    /** @see \\App\\Service\\Mail\\DigestMailBuilder */\n    public const string SEE = '';\n",
    ),
]);
$result = runMove($root, [
    'App\Service\Mail\DigestMailBuilder' => 'App\Service\Mail\Factory\DigestMailFactory',
    'App\Beta\Thing' => 'App\Zulu\Thing',
]);
$consumer = (string) @file_get_contents("{$root}/backend/src/Service/Consumer.php");
$documented = (string) @file_get_contents("{$root}/backend/src/Service/Documented.php");
$moved = (string) @file_get_contents("{$root}/backend/src/Service/Mail/Factory/DigestMailFactory.php");
check(
    '(c) a rewritten use block comes out sorted',
    "use App\Service\Mail\Factory\DigestMailFactory;\nuse App\Zulu\Thing;\nuse App\Zulu\Thing\Part;\n"
        . "use Psr\Log\LoggerInterface;" === importLines($consumer),
    "  use block:\n" . importLines($consumer) . "\n  output: {$result['output']}",
);
check(
    '(d) an untouched file keeps its unsorted use block',
    $unsortedImports === importLines((string) @file_get_contents("{$root}/backend/src/Service/Untouched.php")) . "\n",
    '  use block: ' . importLines((string) @file_get_contents("{$root}/backend/src/Service/Untouched.php")),
);
check(
    '(d) a file rewritten outside its use block keeps that block unsorted',
    str_contains($documented, '@see \App\Service\Mail\Factory\DigestMailFactory') && $unsortedImports === importLines($documented) . "\n",
    "  file:\n{$documented}",
);
check(
    '(e) a clean map still moves',
    0 === $result['status']
        && str_contains($result['output'], 'Moved 2 classes (1 renamed)')
        && str_contains($moved, "namespace App\Service\Mail\Factory;\n")
        && str_contains($moved, 'final class DigestMailFactory')
        && !is_file("{$root}/backend/src/Service/Mail/DigestMailBuilder.php")
        && str_contains($consumer, 'DigestMailFactory $builder'),
    "  exit {$result['status']}, output: {$result['output']}\n  moved file:\n{$moved}",
);

echo 0 === $failures ? "All checks passed.\n" : "{$failures} checks failed.\n";
exit(0 === $failures ? 0 : 1);
