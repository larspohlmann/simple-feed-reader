<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-09-28-1202-scripts/derive-moves.php \
//     var/refactor-1202/roles.json <check,check,...> var/refactor-1202/<map>.php ['<class regex>']
// Reads a report-only ServiceRoleRule run (phpstan --error-format=json), keeps the given checks and the classes the
// regex matches, applies overrides.php, adds each moved class's tests, and writes the map move-classes.php reads.

const HOME = '/^Service role "(?<check>\w+)": (?<class>App\\\\[\w\\\\]+) .*Its home is (?<home>App\\\\[\w\\\\]+)\.$/s';

require __DIR__ . '/class-names.php';

function testNamespaceOf(string $class): string
{
    return 'App\\Tests\\' . substr(namespaceOf($class), strlen('App\\'));
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** @return array<string, string> class => home, for the given checks and classes */
function reportedHomes(string $reportFile, array $checks, string $classPattern): array
{
    $report = json_decode((string) file_get_contents($reportFile), true, flags: JSON_THROW_ON_ERROR);
    $homes = [];
    foreach ($report['files'] ?? [] as $file) {
        foreach ($file['messages'] as $message) {
            if (1 !== preg_match(HOME, $message['message'], $match)) {
                continue;
            }
            if (in_array($match['check'], $checks, true) && 1 === preg_match($classPattern, $match['class'])) {
                $homes[$match['class']][$match['home']] = true;
            }
        }
    }
    $overrides = require __DIR__ . '/overrides.php';
    foreach ($overrides as $class => [$check, $home]) {
        // An override decides both where the class goes and in which PR, whatever the report said.
        unset($homes[$class]);
        if (null !== $home && in_array($check, $checks, true) && 1 === preg_match($classPattern, $class)) {
            $homes[$class] = [$home => true];
        }
    }
    $moves = [];
    foreach ($homes as $class => $candidates) {
        if (1 !== count($candidates)) {
            fail(sprintf('Two homes for %s: %s', $class, implode(', ', array_keys($candidates))));
        }
        $moves[$class] = (string) array_key_first($candidates);
    }

    return $moves;
}

/** @return list<string> the short names of the classes declared next to $class */
function siblingsOf(string $class): array
{
    return array_map(
        static fn (string $file): string => basename($file, '.php'),
        glob(dirname(pathOf($class)) . '/*.php') ?: [],
    );
}

/** @return array<string, string> test class => its name after the class's own short name ("Test", "WiringTest") */
function testsOf(string $class): array
{
    $short = shortNameOf($class);
    $directory = dirname('tests/' . substr(pathOf($class), strlen('src/')));
    $longer = array_filter(
        siblingsOf($class),
        static fn (string $sibling): bool => strlen($sibling) > strlen($short) && str_starts_with($sibling, $short),
    );
    $tests = [];
    foreach (glob($directory . '/' . $short . '*Test.php') ?: [] as $file) {
        $testShort = basename($file, '.php');
        $rest = substr($testShort, strlen($short));
        $claimedByALongerSibling = array_filter(
            $longer,
            static fn (string $sibling): bool => str_starts_with($testShort, $sibling),
        );
        if (('Test' === $rest || ctype_upper($rest[0])) && [] === $claimedByALongerSibling) {
            $tests[testNamespaceOf($class) . '\\' . $testShort] = $rest;
        }
    }

    return $tests;
}

/**
 * A test follows its class and a model's new suffix; a test named after an interface tests its family or its wiring,
 * so it keeps its name when only `Interface` was appended.
 *
 * @param array<string, string> $moves
 */
function withTests(array $moves): array
{
    $all = $moves;
    foreach ($moves as $old => $new) {
        $testedName = shortNameOf($new) === shortNameOf($old) . 'Interface' ? shortNameOf($old) : shortNameOf($new);
        foreach (testsOf($old) as $test => $rest) {
            $all[$test] = testNamespaceOf($new) . '\\' . $testedName . $rest;
        }
    }
    ksort($all);

    return $all;
}

/** @param array<string, string> $moves */
function assertNoCollision(array $moves): void
{
    $targets = [];
    foreach ($moves as $old => $new) {
        if (isset($targets[$new])) {
            fail(sprintf('%s and %s both move to %s', $targets[$new], $old, $new));
        }
        $targets[$new] = $old;
        if (is_file(pathOf($new)) && !isset($moves[$new])) {
            fail(sprintf('%s would overwrite %s', $old, pathOf($new)));
        }
    }
}

[, $reportFile, $checkList, $mapFile] = $argv;
$classPattern = $argv[4] ?? '/./';
$moves = withTests(reportedHomes($reportFile, explode(',', $checkList), $classPattern));
assertNoCollision($moves);
file_put_contents($mapFile, "<?php\n\nreturn " . var_export($moves, true) . ";\n");

$sources = array_filter(array_keys($moves), static fn (string $class): bool => !str_starts_with($class, 'App\\Tests\\'));
printf("%d classes, %d tests\n\n| Class | Moves to |\n|---|---|\n", count($sources), count($moves) - count($sources));
foreach ($sources as $class) {
    printf("| `%s` | `%s` |\n", $class, $moves[$class]);
}
