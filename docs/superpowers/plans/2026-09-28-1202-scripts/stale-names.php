<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-09-28-1202-scripts/stale-names.php var/refactor-1202/<map>.php
// POSIX EREs for `git grep -E \( -f … \) --and --not -e '^namespace '`, one per old FQCN, spelled with one backslash
// between segments (PHP, YAML, neon, Markdown) or two (quoted PHP strings, infection.json5). The `--not` keeps a moved
// file's own namespace, which can be an old FQCN (`…\SearchIndexReader` becomes a folder).

/** @var array<string, string> $moves */
$moves = require $argv[1];
$patterns = [];
foreach (array_keys($moves) as $old) {
    // Segments are [A-Za-z0-9_], never special in an ERE. `\\{1,2}` is one or two backslashes, and `\\` inside a
    // bracket is a literal backslash. A name followed by a word character or a backslash is another class.
    $patterns[] = implode('\\\\{1,2}', explode('\\', $old)) . '([^[:alnum:]_\\\\]|$)';
}
sort($patterns);
echo implode("\n", $patterns), "\n";
