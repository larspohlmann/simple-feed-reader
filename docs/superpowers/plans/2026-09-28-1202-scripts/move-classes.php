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

/** @return array<string, true> the short names a file imports, aliases included */
function importedNames(string $code): array
{
    preg_match_all('/^use (?!function |const )([\w\\\\]+)(?: as (\w+))?;$/m', $code, $matches, PREG_SET_ORDER);
    $names = [];
    foreach ($matches as $match) {
        $names[$match[2] ?? shortNameOf($match[1])] = true;
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

function removeImport(string $code, string $class): string
{
    $trimmed = str_replace("use {$class};\n", '', $code);
    if ($trimmed === $code) {
        return $code;
    }

    return (string) preg_replace_callback(
        '/^(namespace [^;]+;\n)\n\n+/m',
        static fn (array $match): string => $match[1] . "\n",
        $trimmed,
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
    preg_match_all('/^use (?!function |const )([\w\\\\]+);$/m', $code, $matches);
    $classes = [];
    foreach ($matches[1] as $class) {
        $classes[shortNameOf($class)] = $class;
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

/** @param array<string, string> $moves */
function rewriteDocumentPaths(string $text, array $moves): string
{
    foreach ($moves as $old => $new) {
        if (str_starts_with($old, 'App\\Tests\\')) {
            continue;
        }
        $oldPath = substr(pathOf($old), strlen('src/'));
        $newPath = substr(pathOf($new), strlen('src/'));
        $text = (string) preg_replace_callback(
            '/(?<!\w)' . preg_quote($oldPath, '/') . '/',
            static fn (): string => $newPath,
            $text,
        );
    }

    return $text;
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

/** @var array<string, string> $moves */
$moves = require $argv[1];
$oldNamespaces = array_flip(array_map('namespaceOf', array_keys($moves)));
$renames = array_filter(
    $moves,
    static fn (string $new, string $old): bool => shortNameOf($new) !== shortNameOf($old),
    ARRAY_FILTER_USE_BOTH,
);

// Where every class of an affected namespace lives once the moves are done.
$destinations = [];
foreach (phpFiles() as $file) {
    $namespace = declaredNamespace((string) file_get_contents($file));
    $class = $namespace . '\\' . basename($file, '.php');
    if (isset($oldNamespaces[$namespace]) && pathOf($class) === $file) {
        $destinations[$namespace][basename($file, '.php')] = $moves[$class] ?? $class;
    }
}

// 1. Before anything moves: which bare names each file will have to import, and which renamed classes it names bare.
$importsFor = [];
$bareRenamesIn = [];
foreach (phpFiles() as $file) {
    $code = (string) file_get_contents($file);
    $namespace = declaredNamespace($code);
    $class = $namespace . '\\' . basename($file, '.php');
    $destination = $moves[$class] ?? $class;
    $destinationFile = destinationOf($file, $class, $moves);
    $imported = importedClasses($code);
    $importedShortNames = importedNames($code);
    foreach ($renames as $old => $new) {
        $short = shortNameOf($old);
        $namesIt = ($imported[$short] ?? null) === $old
            || (namespaceOf($old) === $namespace && !isset($importedShortNames[$short]));
        if ($namesIt) {
            $bareRenamesIn[$destinationFile][$short] = shortNameOf($new);
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

// 3. Both spellings of every old name, in every tracked file outside docs/ (code, strings, docblocks, config, JSON5,
// Docker, CI, migrations, Markdown) and in docs/architecture.md, become the new one.
$rewrittenFiles = 0;
foreach ([...repositoryFiles(), '../docs/architecture.md'] as $file) {
    $code = (string) file_get_contents($file);
    $isDocument = str_ends_with($file, '.md');
    if (!$isDocument && !str_contains($code, 'App\\')) {
        continue;
    }
    $rewritten = rewriteQualifiedNamesOutsideNamespaceLines($code, $moves);
    if ($isDocument) {
        $rewritten = rewriteDocumentPaths($rewritten, $moves);
    }
    if ($rewritten !== $code) {
        file_put_contents($file, $rewritten);
        ++$rewrittenFiles;
    }
}

// 4. An import of a class that now shares the file's namespace goes.
foreach (phpFiles() as $file) {
    $code = (string) file_get_contents($file);
    $namespace = declaredNamespace($code);
    $trimmed = $code;
    foreach ($moves as $new) {
        if (namespaceOf($new) === $namespace) {
            $trimmed = removeImport($trimmed, $new);
        }
    }
    if ($trimmed !== $code) {
        file_put_contents($file, $trimmed);
    }
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

printf(
    "Moved %d classes (%d renamed); rewrote names in %d files; added %d imports in %d files; "
        . "renamed bare names in %d files.\n",
    count($moves),
    count($renames),
    $rewrittenFiles,
    $added,
    count($importsFor),
    count($bareRenamesIn),
);
