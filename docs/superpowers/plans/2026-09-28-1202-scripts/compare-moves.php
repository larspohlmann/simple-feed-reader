<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-09-28-1202-scripts/compare-moves.php var/refactor-1202/<map>.php [<base ref>]
// A move changes no code: compares each moved file's code tokens with its old self at the base ref (default
// origin/develop), without comments, whitespace, the namespace line or the imports, and with the map applied to the
// old side's names. Prints each file that still differs, and where.

function pathOf(string $class): string
{
    $relative = str_starts_with($class, 'App\\Tests\\')
        ? 'tests/' . substr($class, strlen('App\\Tests\\'))
        : 'src/' . substr($class, strlen('App\\'));

    return str_replace('\\', '/', $relative) . '.php';
}

function shortNameOf(string $class): string
{
    return substr($class, (int) strrpos($class, '\\') + 1);
}

/** @return list<string> */
function codeTokens(string $code): array
{
    $kept = [];
    $depth = 0;
    $skipping = false;
    foreach (token_get_all($code) as $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) {
            continue;
        }
        if ('{' === $text || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id) {
            ++$depth;
        } elseif ('}' === $text) {
            --$depth;
        }
        if (0 === $depth && in_array($id, [T_NAMESPACE, T_USE], true)) {
            $skipping = true;

            continue;
        }
        if ($skipping) {
            $skipping = ';' !== $text;

            continue;
        }
        $kept[] = $text;
    }

    return $kept;
}

/**
 * @param array<string, string> $moves
 * @param array<string, string> $shortRenames
 *
 * @return \Closure(string): string what a token of the old file reads as after the moves
 */
function renamer(array $moves, array $shortRenames): \Closure
{
    $names = [];
    foreach ($moves as $old => $new) {
        $names[$old] = $new;
        $names[str_replace('\\', '\\\\', $old)] = str_replace('\\', '\\\\', $new);
    }
    uksort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $alternation = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_keys($names)));
    $pattern = '/(?<![\w\\\\])(\\\\{0,2})(' . $alternation . ')(?![\w\\\\])/';

    return static function (string $token) use ($names, $pattern, $shortRenames): string {
        if (isset($shortRenames[$token])) {
            return $shortRenames[$token];
        }
        if (!str_contains($token, 'App\\')) {
            return $token;
        }

        return (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => $match[1] . $names[$match[2]],
            $token,
        );
    };
}

/** @var array<string, string> $moves */
$moves = require $argv[1];
$base = $argv[2] ?? 'origin/develop';
$shortRenames = [];
foreach ($moves as $old => $new) {
    if (shortNameOf($old) !== shortNameOf($new)) {
        $shortRenames[shortNameOf($old)] = shortNameOf($new);
    }
}

$rename = renamer($moves, $shortRenames);
$differing = 0;
foreach ($moves as $old => $new) {
    $before = (string) shell_exec(sprintf('git show %s', escapeshellarg($base . ':backend/' . pathOf($old))));
    $oldTokens = array_map($rename, codeTokens($before));
    $newTokens = codeTokens((string) file_get_contents(pathOf($new)));
    if ($oldTokens === $newTokens) {
        continue;
    }
    ++$differing;
    $at = 0;
    while (($oldTokens[$at] ?? null) === ($newTokens[$at] ?? null)) {
        ++$at;
    }
    printf(
        "%s differs at token %d: '%s' became '%s'\n",
        pathOf($new),
        $at,
        implode(' ', array_slice($oldTokens, $at, 8)),
        implode(' ', array_slice($newTokens, $at, 8)),
    );
}

printf("%d of %d moved files differ in code.\n", $differing, count($moves));
