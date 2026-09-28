<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-09-28-1202-scripts/move-classes.php var/refactor-1202/<map>.php
// The map returns array<string, string>: old FQCN => new FQCN, for src and test classes alike. #1162's script (#1161
// A0 Step 3) plus: a short name may change, names are rewritten in one pass, a moved file's relative paths follow it,
// and every tracked file outside docs/ follows the moves, with docs/architecture.md (#1202 D16).

const TYPE_TAGS = '@(?:phpstan-|psalm-)?(?:param|return|var|throws|extends|implements|use|mixin'
    . '|template(?:-covariant|-contravariant)?|assert(?:-if-true|-if-false)?|property(?:-read|-write)?'
    . '|method|self-out|this-out|require-extends|require-implements)\b';

require __DIR__ . '/class-names.php';

function run(string $command): void
{
    passthru($command, $status);
    if (0 !== $status) {
        fwrite(STDERR, "Failed: {$command}\n");
        exit(1);
    }
}

/**
 * Every tracked text file in the repository, as a path relative to backend/, where this script runs. Never docs/ (the
 * committed plans are history) and never a PHPStan rule or fixture (their class names are test data).
 *
 * @return list<string>
 */
function repositoryFiles(): array
{
    $files = [];
    foreach (explode("\0", (string) shell_exec('git -C .. ls-files -z')) as $path) {
        if ('' === $path || str_starts_with($path, 'docs/') || str_starts_with($path, 'backend/tests/PhpStan/')) {
            continue;
        }
        $relative = str_starts_with($path, 'backend/') ? substr($path, strlen('backend/')) : '../' . $path;
        if (is_file($relative) && !str_contains((string) file_get_contents($relative, length: 8000), "\0")) {
            $files[] = $relative;
        }
    }

    return $files;
}

/** @return list<string> every tracked PHP file: src and tests, and the scripts outside them (docker/, migrations/) */
function phpFiles(): array
{
    return array_values(array_filter(
        repositoryFiles(),
        static fn (string $file): bool => str_ends_with($file, '.php'),
    ));
}

/**
 * Where a file is once the moves are done: a moved class's new path, any other file where it is. A file that is not
 * its class's PSR-4 path (docker/php/worker-healthcheck.php, a migration) never moves.
 *
 * @param array<string, string> $moves
 */
function destinationOf(string $file, string $class, array $moves): string
{
    return pathOf($class) === $file && isset($moves[$class]) ? pathOf($moves[$class]) : $file;
}

function declaredNamespace(string $code): string
{
    return 1 === preg_match('/^namespace ([^;]+);$/m', $code, $match) ? $match[1] : '';
}

/** @return array{class: string, alias: ?string}|null a plain class import; `use function` and `use const` are not one */
function parseImport(string $line): ?array
{
    if (1 !== preg_match('/^use (?!function |const )([\w\\\\]+)(?: as (\w+))?;$/', $line, $import)) {
        return null;
    }

    return ['class' => $import[1], 'alias' => $import[2] ?? null];
}

/** @return array<string, true> the short names a file imports, aliases included */
function importedNames(string $code): array
{
    $names = [];
    foreach (explode("\n", $code) as $line) {
        $import = parseImport($line);
        if (null !== $import) {
            $names[$import['alias'] ?? shortNameOf($import['class'])] = true;
        }
    }

    return $names;
}

/** @param list<mixed> $tokens */
function significantNeighbour(array $tokens, int $index, int $step): mixed
{
    for ($position = $index + $step; isset($tokens[$position]); $position += $step) {
        $token = $tokens[$position];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $token;
    }

    return null;
}

/** @param list<mixed> $tokens */
function isClassPosition(array $tokens, int $index): bool
{
    $previous = significantNeighbour($tokens, $index, -1);
    $next = significantNeighbour($tokens, $index, 1);
    $previousId = is_array($previous) ? $previous[0] : $previous;
    $memberOrDeclaration = [
        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST,
        T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO,
    ];
    if (in_array($previousId, $memberOrDeclaration, true)) {
        return false;
    }
    if (T_CASE === $previousId) {
        return is_array($next) && T_DOUBLE_COLON === $next[0];
    }

    return ':' !== $next;
}

function typeExpressionAfter(string $text, int $offset): string
{
    $length = strlen($text);
    while ($offset < $length && ctype_space($text[$offset])) {
        ++$offset;
    }
    $depth = 0;
    $type = '';
    for (; $offset < $length; ++$offset) {
        $char = $text[$offset];
        if (0 === $depth && ctype_space($char)) {
            if (!str_ends_with($type, ':')) {
                break;
            }

            continue;
        }
        $depth += match ($char) {
            '<', '{', '(', '[' => 1,
            '>', '}', ')', ']' => -1,
            default => 0,
        };
        $type .= $char;
    }

    return $type;
}

/** @return list<string> */
function identifiersIn(string $type): array
{
    preg_match_all('/(?<![\w\\\\$-])[A-Za-z_]\w*(?![\w\\\\-])(?!\s*\??:(?!:))/', $type, $matches);

    return $matches[0];
}

/** @return list<string> class names a docblock uses as a type, an imported type's source, or a @see target */
function docblockNames(string $docblock): array
{
    $text = (string) preg_replace(['~^\s*/\*\*~', '~\*/\s*$~', '~^\s*\*~m'], ' ', $docblock);
    $names = [];
    preg_match_all('/' . TYPE_TAGS . '/', $text, $tags, PREG_OFFSET_CAPTURE);
    foreach ($tags[0] as [$tag, $offset]) {
        array_push($names, ...identifiersIn(typeExpressionAfter($text, $offset + strlen($tag))));
    }
    preg_match_all('/@(?:phpstan-|psalm-)?import-type\s+\w+\s+from\s+([A-Za-z_]\w*)(?![\w\\\\])/', $text, $imports);
    preg_match_all('/(?:\{@see|@see|@uses)\s+([A-Za-z_]\w*)(?![\w\\\\])/', $text, $sees);

    return [...$names, ...$imports[1], ...$sees[1]];
}

/** @return list<string> the names a docblock uses as a type or a @see target that are qualified, but not fully */
function docblockQualifiedNames(string $docblock): array
{
    $text = (string) preg_replace(['~^\s*/\*\*~', '~\*/\s*$~', '~^\s*\*~m'], ' ', $docblock);
    $types = [];
    preg_match_all('/' . TYPE_TAGS . '/', $text, $tags, PREG_OFFSET_CAPTURE);
    foreach ($tags[0] as [$tag, $offset]) {
        $types[] = typeExpressionAfter($text, $offset + strlen($tag));
    }
    preg_match_all('/(?:\{@see|@see|@uses)\s+(\S+)/', $text, $sees);
    preg_match_all('/(?<![\w\\\\$-])[A-Za-z_]\w*(?:\\\\\w+)+(?![\w\\\\-])/', implode(' ', [...$types, ...$sees[1]]), $names);

    return $names[0];
}

/** @return array<string, true> the qualified names in the code and its docblocks that resolve against its namespace */
function namespaceRelativeNames(string $code): array
{
    $imported = importedNames($code);
    $names = [];
    $inStatementHead = false;
    foreach (token_get_all($code) as $token) {
        if (!is_array($token)) {
            $inStatementHead = $inStatementHead && ';' !== $token && '{' !== $token;

            continue;
        }
        if (in_array($token[0], [T_USE, T_NAMESPACE], true)) {
            $inStatementHead = true;

            continue;
        }
        $candidates = match (true) {
            T_DOC_COMMENT === $token[0] => docblockQualifiedNames($token[1]),
            T_NAME_QUALIFIED === $token[0] && !$inStatementHead => [$token[1]],
            default => [],
        };
        foreach ($candidates as $name) {
            if (!isset($imported[strstr($name, '\\', true)])) {
                $names[$name] = true;
            }
        }
    }

    return $names;
}

/** @param array<string, string> $replacements relative name => what the file names it by now */
function replaceRelativeNames(string $code, array $replacements): string
{
    $alternation = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_keys($replacements)));
    $pattern = '/(?<![\w\\\\$-])(' . $alternation . ')(?![\w\\\\-])/';
    $replaced = '';
    foreach (token_get_all($code) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $isName = is_array($token) && in_array($token[0], [T_DOC_COMMENT, T_NAME_QUALIFIED], true);
        $replaced .= $isName
            ? (string) preg_replace_callback($pattern, static fn (array $match): string => $replacements[$match[1]], $text)
            : $text;
    }

    return $replaced;
}

/** @return array<string, true> bare names the code and its docblock types use as class names */
function referencedNames(string $code): array
{
    $tokens = token_get_all($code);
    $names = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        if (T_DOC_COMMENT === $token[0]) {
            foreach (docblockNames($token[1]) as $name) {
                $names[$name] = true;
            }

            continue;
        }
        if (T_STRING === $token[0] && isClassPosition($tokens, $index)) {
            $names[$token[1]] = true;
        }
    }

    return $names;
}

/** A plain import of a direct member of the namespace, or one aliased to its own short name, changes nothing. */
function isOwnNamespaceImport(string $line, string $namespace): bool
{
    $import = parseImport($line);
    if (null === $import) {
        return false;
    }
    $short = shortNameOf($import['class']);

    return namespaceOf($import['class']) === $namespace && ($import['alias'] ?? $short) === $short;
}

/** A use block that loses every line takes the blank line after it along. */
function withoutOwnNamespaceImports(string $code): string
{
    $namespace = declaredNamespace($code);

    return (string) preg_replace_callback(
        '/(?:^use [^;\n]+;\n)+(\n?)/m',
        static function (array $block) use ($namespace): string {
            $lines = explode("\n", rtrim($block[0], "\n"));
            $kept = array_filter($lines, static fn (string $line): bool => !isOwnNamespaceImport($line, $namespace));

            return [] === $kept ? '' : implode("\n", $kept) . "\n" . $block[1];
        },
        $code,
    );
}

function addImport(string $code, string $class): string
{
    $line = "use {$class};\n";
    if (str_contains($code, "\n{$line}")) {
        return $code;
    }
    preg_match_all(
        '/^use (?!function |const )([\w\\\\]+)(?: as \w+)?;\n/m',
        $code,
        $imports,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    if ([] === $imports) {
        $namespaceEnd = (int) strpos($code, ";\n", (int) strpos($code, "\nnamespace ")) + 2;

        return substr_replace($code, "\n" . $line, $namespaceEnd, 0);
    }
    foreach ($imports as $import) {
        if (strcasecmp($import[1][0], $class) > 0) {
            return substr_replace($code, $line, $import[0][1], 0);
        }
    }
    $last = $imports[array_key_last($imports)];

    return substr_replace($code, $line, $last[0][1] + strlen($last[0][0]), 0);
}

/** @return array<string, string> short name => class, for the imports that carry no alias */
function importedClasses(string $code): array
{
    $classes = [];
    foreach (explode("\n", $code) as $line) {
        $import = parseImport($line);
        if (null !== $import && null === $import['alias']) {
            $classes[shortNameOf($import['class'])] = $import['class'];
        }
    }

    return $classes;
}

/**
 * One pass over every old name, longest first, so a new name that starts with an old one is never rewritten again.
 * A leading separator is kept; a name followed by a separator is a namespace, not the class, and stays.
 *
 * @param array<string, string> $names
 */
function rewriteNames(string $text, array $names, string $separator): string
{
    uksort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $alternation = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_keys($names)));
    $escapedSeparator = preg_quote($separator, '/');

    return (string) preg_replace_callback(
        '/(?<![\w\\\\])((?:' . $escapedSeparator . ')?)(' . $alternation . ')(?![\w\\\\])/',
        static fn (array $match): string => $match[1] . $names[$match[2]],
        $text,
    );
}

/**
 * Both spellings of every old FQCN: `App\Service\X` (PHP, YAML, neon, Markdown) and the doubled `App\\Service\\X` of
 * quoted PHP and JSON5 strings, such as infection.json5's `ignore` entries. Neither pass can match the other's
 * spelling: one needs a single backslash between segments, the other two.
 *
 * @param array<string, string> $moves
 */
function rewriteQualifiedNames(string $text, array $moves): string
{
    $doubled = [];
    foreach ($moves as $old => $new) {
        $doubled[str_replace('\\', '\\\\', $old)] = str_replace('\\', '\\\\', $new);
    }

    return rewriteNames(rewriteNames($text, $moves, '\\'), $doubled, '\\\\');
}

/**
 * A namespace declaration is step 2's alone: an old FQCN can be exactly a moved file's new namespace
 * (`…\SearchIndexReader` becomes the folder of `…\SearchIndexReader\SearchIndexReaderInterface`).
 *
 * @param array<string, string> $moves
 */
function rewriteQualifiedNamesOutsideNamespaceLines(string $text, array $moves): string
{
    $parts = preg_split('/^(namespace [^;{]+;)$/m', $text, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    foreach ($parts as $index => $part) {
        if (0 === $index % 2) {
            $parts[$index] = rewriteQualifiedNames($part, $moves);
        }
    }

    return implode('', $parts);
}

/** A source file's path as config and prose name it: from below src/ (`Service/…/X.php`), a test's from tests/. */
function pathReferenceOf(string $class): string
{
    return str_starts_with($class, 'App\\Tests\\') ? pathOf($class) : substr(pathOf($class), strlen('src/'));
}

/** @param array<string, string> $moves */
function rewritePaths(string $text, array $moves): string
{
    $paths = [];
    foreach ($moves as $old => $new) {
        $paths[pathReferenceOf($old)] = pathReferenceOf($new);
    }
    uksort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $alternation = implode('|', array_map(static fn (string $path): string => preg_quote($path, '/'), array_keys($paths)));

    return (string) preg_replace_callback(
        '/(?<!\w)(' . $alternation . ')/',
        static fn (array $match): string => $paths[$match[1]],
        $text,
    );
}

/** @param list<mixed> $tokens */
function isDeclaredName(array $tokens, int $index): bool
{
    $previous = significantNeighbour($tokens, $index, -1);

    return is_array($previous) && in_array($previous[0], [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT], true);
}

/** @param array<string, string> $renames old short name => new short name */
function renameInProse(string $text, array $renames): string
{
    $alternation = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_keys($renames)));

    return (string) preg_replace_callback(
        '/(?<![\w\\\\$])(' . $alternation . ')(?!\w)/',
        static fn (array $match): string => $renames[$match[1]],
        $text,
    );
}

/** @param array<string, string> $renames old short name => new short name */
function renameBareNames(string $code, array $renames): string
{
    $tokens = token_get_all($code);
    $renamed = '';
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            $renamed .= $token;

            continue;
        }
        [$id, $text] = $token;
        $isName = T_STRING === $id && isset($renames[$text])
            && (isClassPosition($tokens, $index) || isDeclaredName($tokens, $index));
        if ($isName) {
            $renamed .= $renames[$text];

            continue;
        }
        $renamed .= in_array($id, [T_COMMENT, T_DOC_COMMENT], true) ? renameInProse($text, $renames) : $text;
    }

    return $renamed;
}

/** A moved file reaches the same places: `dirname(__DIR__, n)` and `__DIR__ . '/../…'` follow its new depth. */
function shiftRelativePaths(string $code, int $delta, string $file): string
{
    $code = (string) preg_replace_callback(
        '/(\\\\?dirname\(__DIR__)(?:,\s*(\d+))?\)/',
        static function (array $match) use ($delta): string {
            $levels = ('' === ($match[2] ?? '') ? 1 : (int) $match[2]) + $delta;

            return 1 === $levels ? $match[1] . ')' : $match[1] . ', ' . $levels . ')';
        },
        $code,
    );

    return (string) preg_replace_callback(
        "/__DIR__(\\s*\\.\\s*')\\/((?:\\.\\.\\/)*)/",
        static function (array $match) use ($delta, $file): string {
            $levels = intdiv(strlen($match[2]), 3) + $delta;
            if ('' === $match[2] || $levels < 1) {
                fwrite(STDERR, "Fix the __DIR__ path in {$file} by hand: it points inside the old folder\n");
                exit(1);
            }

            return '__DIR__' . $match[1] . '/' . str_repeat('../', $levels);
        },
        $code,
    );
}

function depthOf(string $path): int
{
    return substr_count(dirname($path), '/');
}

/** @return list<string> the short names of the classes, interfaces, traits and enums a file declares */
function declaredShortNames(string $code): array
{
    $tokens = token_get_all($code);
    $names = [];
    foreach ($tokens as $index => $token) {
        if (is_array($token) && T_STRING === $token[0] && isDeclaredName($tokens, $index)) {
            $names[] = $token[1];
        }
    }

    return $names;
}

/**
 * The names a file already gives classes once the moves are done: its imports, its declarations and its namespace.
 *
 * @param array<string, string> $moves
 * @param array<string, string> $namespaceClasses short name => class, for the classes of the file's final namespace
 *
 * @return array<string, array<string, string>> short name => class => what gives the file that name
 */
function namesAfterMoves(string $code, string $destination, array $moves, array $namespaceClasses): array
{
    $names = [];
    foreach ($namespaceClasses as $short => $class) {
        $names[$short][$class] = 'its namespace holds';
    }
    foreach (declaredShortNames($code) as $short) {
        $class = $moves[declaredNamespace($code) . '\\' . $short] ?? namespaceOf($destination) . '\\' . $short;
        $names[shortNameOf($class)][$class] = 'the file declares';
    }
    foreach (explode("\n", $code) as $line) {
        $import = parseImport($line);
        if (null === $import) {
            continue;
        }
        $class = $moves[$import['class']] ?? $import['class'];
        $short = $import['alias'] ?? shortNameOf($import['class']);
        $isRenamedBareImport = null === $import['alias'] && shortNameOf($class) !== $short;
        if (!$isRenamedBareImport) {
            $names[$short][$class] = 'an import names';
        }
    }

    return $names;
}

/**
 * @param array<string, array<string, true>> $introduced short name => the classes the moves make the file name by it
 * @param array<string, array<string, string>> $existing short name => class => what gives the file that name
 *
 * @return list<string>
 */
function collisionsIn(string $file, array $introduced, array $existing): array
{
    $collisions = [];
    foreach ($introduced as $short => $classes) {
        $classes = array_keys($classes);
        if (count($classes) > 1) {
            $collisions[] = sprintf('%s: %s would name %s', $file, $short, implode(' and ', $classes));
        }
        foreach ($classes as $class) {
            foreach ($existing[$short] ?? [] as $other => $source) {
                if ($other !== $class) {
                    $collisions[] = sprintf('%s: %s would name %s, but %s %s', $file, $short, $class, $source, $other);
                }
            }
        }
    }

    return $collisions;
}

/**
 * @param array<string, string> $moves
 *
 * @return list<string> the paths two moved classes, or a moved class and an existing file, would share
 */
function landingCollisions(array $moves): array
{
    $landings = [];
    foreach ($moves as $old => $new) {
        $landings[pathOf($new)][] = $old;
    }
    $collisions = [];
    foreach ($landings as $path => $classes) {
        if (count($classes) > 1) {
            $collisions[] = sprintf('%s: %s would both land there', $path, implode(' and ', $classes));
        }
        if (is_file($path)) {
            $collisions[] = sprintf('%s: %s would land on an existing file', $path, implode(' and ', $classes));
        }
    }

    return $collisions;
}

function importBlocks(string $code): string
{
    preg_match_all('/(?:^use [^;\n]+;\n)+/m', $code, $blocks);

    return implode("\n", $blocks[0]);
}

/** php-cs-fixer's `ordered_imports` alpha order: case-insensitive, a backslash as a space, functions and constants last */
function importSortKey(string $line): string
{
    $statement = rtrim($line, ';');
    $group = match (true) {
        str_starts_with($statement, 'use function ') => '1',
        str_starts_with($statement, 'use const ') => '2',
        default => '0',
    };

    return $group . strtolower(str_replace('\\', ' ', $statement));
}

function sortImports(string $code): string
{
    return (string) preg_replace_callback(
        '/(?:^use [^;\n]+;\n)+/m',
        static function (array $block): string {
            $lines = explode("\n", rtrim($block[0], "\n"));
            usort($lines, static fn (string $a, string $b): int => strcmp(importSortKey($a), importSortKey($b)));

            return implode("\n", $lines) . "\n";
        },
        $code,
    );
}

/** @var array<string, string> $moves */
$moves = require $argv[1];
$oldNamespaces = array_flip(array_map('namespaceOf', array_keys($moves)));
$renames = array_filter(
    $moves,
    static fn (string $new, string $old): bool => shortNameOf($new) !== shortNameOf($old),
    ARRAY_FILTER_USE_BOTH,
);

// Where every class lives once the moves are done: by its old namespace in an affected one, and by its new namespace.
$destinations = [];
$finalClasses = [];
foreach (phpFiles() as $file) {
    $namespace = declaredNamespace((string) file_get_contents($file));
    $class = $namespace . '\\' . basename($file, '.php');
    if (pathOf($class) !== $file) {
        continue;
    }
    $final = $moves[$class] ?? $class;
    $finalClasses[namespaceOf($final)][shortNameOf($final)] = $final;
    if (isset($oldNamespaces[$namespace])) {
        $destinations[$namespace][basename($file, '.php')] = $final;
    }
}

// 1. Before anything moves: which bare names each file will have to import, which renamed classes it names bare, and
// which names relative to its namespace stop resolving there.
$importsFor = [];
$bareRenamesIn = [];
$relativeNamesIn = [];
$introducedIn = [];
$namesIn = [];
$importBlocksBefore = [];
$contentsBefore = [];
foreach (phpFiles() as $file) {
    $code = (string) file_get_contents($file);
    $namespace = declaredNamespace($code);
    $class = $namespace . '\\' . basename($file, '.php');
    $destination = $moves[$class] ?? $class;
    $destinationFile = destinationOf($file, $class, $moves);
    $namespaceClasses = $finalClasses[namespaceOf($destination)] ?? [];
    $namesIn[$destinationFile] = namesAfterMoves($code, $destination, $moves, $namespaceClasses);
    $importBlocksBefore[$destinationFile] = importBlocks($code);
    $contentsBefore[$destinationFile] = $code;
    $imported = importedClasses($code);
    $importedShortNames = importedNames($code);
    foreach ($renames as $old => $new) {
        $short = shortNameOf($old);
        $namesIt = ($imported[$short] ?? null) === $old
            || (namespaceOf($old) === $namespace && !isset($importedShortNames[$short]));
        if ($namesIt) {
            $bareRenamesIn[$destinationFile][$short] = shortNameOf($new);
            $introducedIn[$destinationFile][shortNameOf($new)][$new] = true;
        }
    }
    foreach (array_keys(namespaceRelativeNames($code)) as $relative) {
        $target = $moves[$namespace . '\\' . $relative] ?? $namespace . '\\' . $relative;
        if (namespaceOf($destination) . '\\' . $relative === $target) {
            continue;
        }
        $isTaken = isset($importedShortNames[shortNameOf($target)]) || shortNameOf($destination) === shortNameOf($target);
        $relativeNamesIn[$destinationFile][$relative] = $isTaken ? '\\' . $target : shortNameOf($target);
        if (!$isTaken) {
            $introducedIn[$destinationFile][shortNameOf($target)][$target] = true;
        }
        if (!$isTaken && namespaceOf($target) !== namespaceOf($destination)) {
            $importsFor[$destinationFile][$target] = true;
        }
    }
    if (!isset($oldNamespaces[$namespace]) || pathOf($class) !== $file) {
        continue;
    }
    foreach (array_keys(referencedNames($code)) as $name) {
        $target = $destinations[$namespace][(string) $name] ?? null;
        if (null === $target || isset($importedShortNames[$name]) || $target === $destination) {
            continue;
        }
        if (namespaceOf($target) !== namespaceOf($destination)) {
            $importsFor[$destinationFile][$target] = true;
        }
    }
}

// A name the moves bring into a file must not be one it already gives another class: stop before anything changes.
foreach ($importsFor as $file => $classes) {
    foreach (array_keys($classes) as $imported) {
        $introducedIn[$file][shortNameOf((string) $imported)][(string) $imported] = true;
    }
}
$collisions = landingCollisions($moves);
foreach ($introducedIn as $file => $introduced) {
    array_push($collisions, ...collisionsIn($file, $introduced, $namesIn[$file]));
}
foreach ($collisions as $collision) {
    fwrite(STDERR, "Failed: {$collision}\n");
}
if ([] !== $collisions) {
    exit(1);
}

// 2. Each file moves, and its namespace line and its relative paths with it.
foreach ($moves as $old => $new) {
    $from = pathOf($old);
    $to = pathOf($new);
    if (!is_dir(dirname($to))) {
        mkdir(dirname($to), 0o775, true);
    }
    run(sprintf('git mv %s %s', escapeshellarg($from), escapeshellarg($to)));
    $code = str_replace(
        'namespace ' . namespaceOf($old) . ";\n",
        'namespace ' . namespaceOf($new) . ";\n",
        (string) file_get_contents($to),
    );
    if (dirname($from) !== dirname($to)) {
        $code = shiftRelativePaths($code, depthOf($to) - depthOf($from), $to);
    }
    file_put_contents($to, $code);
}

// 3. Both spellings of every old name and every old path, in every tracked file outside docs/ (code, strings,
// docblocks, config, JSON5, Docker, CI, migrations, Markdown) and in docs/architecture.md, become the new one.
$rewrittenFiles = 0;
foreach ([...repositoryFiles(), '../docs/architecture.md'] as $file) {
    $code = (string) file_get_contents($file);
    if (!str_contains($code, 'App\\') && !str_contains($code, '.php')) {
        continue;
    }
    $rewritten = rewritePaths(rewriteQualifiedNamesOutsideNamespaceLines($code, $moves), $moves);
    if ($rewritten !== $code) {
        file_put_contents($file, $rewritten);
        ++$rewrittenFiles;
    }
}

// 4. A relative name that no longer resolves names its class by an import, or in full where the short name is taken.
foreach ($relativeNamesIn as $file => $replacements) {
    file_put_contents($file, replaceRelativeNames((string) file_get_contents($file), $replacements));
}

// 5. A bare name that left the file's namespace gets an import.
$added = 0;
foreach ($importsFor as $file => $classes) {
    $code = (string) file_get_contents($file);
    foreach (array_keys($classes) as $class) {
        $code = addImport($code, (string) $class);
        ++$added;
    }
    file_put_contents($file, $code);
}

// 6. A renamed class is renamed where a file named it bare: its declaration, its uses, its comments.
foreach ($bareRenamesIn as $file => $shortRenames) {
    $code = (string) file_get_contents($file);
    $renamed = renameBareNames($code, $shortRenames);
    if ($renamed !== $code) {
        file_put_contents($file, $renamed);
    }
}

// 7. A file the moves rewrote drops its imports of classes in its own namespace, such as one it moved in beside.
$trimmedFiles = 0;
foreach ($contentsBefore as $file => $before) {
    $code = (string) file_get_contents($file);
    $trimmed = withoutOwnNamespaceImports($code);
    if ($code !== $before && $trimmed !== $code) {
        file_put_contents($file, $trimmed);
        ++$trimmedFiles;
    }
}

// 8. An import block the moves changed is sorted again; one they left alone keeps its order.
$sorted = 0;
foreach ($importBlocksBefore as $file => $before) {
    $code = (string) file_get_contents($file);
    if (importBlocks($code) !== $before) {
        file_put_contents($file, sortImports($code));
        ++$sorted;
    }
}

printf(
    "Moved %d classes (%d renamed); rewrote names in %d files; added %d imports in %d files; "
        . "renamed bare names in %d files; dropped own-namespace imports in %d files; "
        . "sorted imports in %d files.\n",
    count($moves),
    count($renames),
    $rewrittenFiles,
    $added,
    count($importsFor),
    count($bareRenamesIn),
    $trimmedFiles,
    $sorted,
);
