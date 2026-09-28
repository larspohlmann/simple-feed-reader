<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-09-28-1202-scripts/stale-names.php var/refactor-1202/<map>.php
// One POSIX ERE per old FQCN of the map, for `git grep -E -f`: it matches the name spelled with one backslash between
// segments (PHP, YAML, neon, Markdown) or two (quoted PHP strings, infection.json5). A name followed by a word
// character or a backslash is a longer name or a namespace, not this class, and does not match.

/** @var array<string, string> $moves */
$moves = require $argv[1];
$patterns = [];
foreach (array_keys($moves) as $old) {
    // Segments are [A-Za-z0-9_], never special in an ERE. The output reads `\\{1,2}` (one or two backslashes) between
    // segments, and `\\` inside the closing bracket, which POSIX reads as a literal backslash either way.
    $patterns[] = implode('\\\\{1,2}', explode('\\', $old)) . '([^[:alnum:]_\\\\]|$)';
}
sort($patterns);
echo implode("\n", $patterns), "\n";
